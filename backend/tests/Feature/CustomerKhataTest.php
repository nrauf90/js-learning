<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DayBalance;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Services\Pos\DayBookService;
use Carbon\Carbon;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

/**
 * The udhaar khata — the credit notebook a mohalla store actually runs on.
 *
 * The question every test here is really asking is the one the notebook
 * answers: after all this buying, paying and returning, how much does this
 * person owe? Nothing stores that figure, so the interesting cases are the ones
 * where the till and the khata could drift apart — two names for one man, a
 * lump sum spread over four tickets, goods coming back after they were paid for.
 */
class CustomerKhataTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpenseCategorySeeder::class);
    }

    private function seller(): User
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function product(User $user, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Cola 500ml',
            'price' => 120,
            'cost' => 90,
            'track_stock' => true,
            'stock_quantity' => 500,
            'low_stock_threshold' => 2,
            'is_active' => true,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sell(User $user, array $payload): array
    {
        return $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', $payload)
            ->assertCreated()
            ->json('sale');
    }

    /**
     * A credit sale for `quantity` units at Rs 120 each.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function onCredit(User $user, Product $product, float $quantity, array $extra = []): array
    {
        return $this->sell($user, array_merge([
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
        ], $extra));
    }

    /**
     * Move a sale back in time — `sold_at` on the endpoint only reaches 30 days.
     * Any deposit goes with it: it was handed over at that till, on that day.
     */
    private function backdate(int $saleId, Carbon $soldAt): Sale
    {
        $sale = Sale::findOrFail($saleId);
        $sale->forceFill(['sold_at' => $soldAt])->save();
        $sale->payments()->update(['paid_at' => $soldAt]);

        return $sale;
    }

    /* ------------------------------------------------------ linking the sale */

    public function test_a_credit_sale_opens_a_khata_page_and_links_to_it(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->onCredit($user, $product, 2, ['customer_phone' => '0300-1234567']);

        $customer = Customer::query()->firstOrFail();

        $this->assertSame($user->id, $customer->user_id);
        $this->assertSame('Bilal Traders', $customer->name);
        $this->assertSame('0300-1234567', $customer->phone);
        $this->assertSame($customer->id, $sale['customer_id']);

        // The snapshot on the sale survives alongside the link — it is the
        // record of who took the goods, not a pointer to who owes for them.
        $this->assertSame('Bilal Traders', $sale['customer_name']);
        $this->assertSame('0300-1234567', $sale['customer_phone']);

        $this->assertEquals(240.0, $customer->outstandingBalance());
    }

    public function test_two_sales_to_the_same_name_and_number_land_on_one_page(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $first = $this->onCredit($user, $product, 2, ['customer_phone' => '0300-1234567']);
        $second = $this->onCredit($user, $product, 1, ['customer_phone' => '0300-1234567']);

        $this->assertSame(1, Customer::query()->count());
        $this->assertSame($first['customer_id'], $second['customer_id']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/customers/{$first['customer_id']}")
            ->assertOk()
            ->assertJsonPath('customer.balance', 360);
    }

    public function test_the_same_name_with_a_different_number_is_a_different_person(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $first = $this->onCredit($user, $product, 1, ['customer_name' => 'Ali', 'customer_phone' => '0300-1111111']);
        $second = $this->onCredit($user, $product, 1, ['customer_name' => 'Ali', 'customer_phone' => '0345-2222222']);

        // Two men called Ali is the ordinary case in a mohalla. Merging their
        // debts on the strength of a shared first name would be worse than
        // leaving the owner two pages to reconcile by hand.
        $this->assertNotSame($first['customer_id'], $second['customer_id']);
        $this->assertSame(2, Customer::query()->count());
    }

    public function test_a_page_opened_without_a_number_learns_one_later(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $first = $this->onCredit($user, $product, 1, ['customer_name' => 'Ali Raza']);
        $second = $this->onCredit($user, $product, 1, ['customer_name' => 'ali raza', 'customer_phone' => '0300-9999999']);

        // Same page — matched on the name because there was no number to
        // contradict it — and now it carries the number for next time.
        $this->assertSame($first['customer_id'], $second['customer_id']);
        $this->assertSame('0300-9999999', Customer::findOrFail($first['customer_id'])->phone);
    }

    public function test_a_cash_sale_does_not_open_a_khata_page(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'customer_name' => 'Bilal Traders',
        ]);

        // Nothing is owed, so there is nothing to chase and nobody to file.
        $this->assertNull($sale['customer_id']);
        $this->assertSame(0, Customer::query()->count());
    }

    /* ------------------------------------------------------------- balances */

    public function test_the_deposit_taken_at_the_till_is_already_off_the_balance(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->onCredit($user, $product, 2, ['paid_amount' => 100, 'deposit_method' => 'cash']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/customers/{$sale['customer_id']}")
            ->assertOk()
            ->assertJsonPath('customer.balance', 140);
    }

    public function test_the_outstanding_list_ages_each_debt_and_totals_the_khata(): void
    {
        // Pinned so the bucket edges are known rather than sliding with the
        // day the suite happens to run.
        $this->travelTo(Carbon::create(2026, 6, 15, 11, 0));

        $user = $this->seller();
        $product = $this->product($user);

        $fresh = $this->onCredit($user, $product, 1, ['customer_name' => 'Ali', 'customer_phone' => '0300-1111111']);
        $mid = $this->onCredit($user, $product, 2, ['customer_name' => 'Ali', 'customer_phone' => '0300-1111111']);
        $stale = $this->onCredit($user, $product, 3, ['customer_name' => 'Kareem', 'customer_phone' => '0345-2222222']);

        $this->backdate($mid['id'], Carbon::create(2026, 4, 20, 9, 0));   // 56 days
        $this->backdate($stale['id'], Carbon::create(2025, 11, 1, 9, 0)); // well over 90

        $body = $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/outstanding')
            ->assertOk()
            ->json();

        $this->assertSame(2, $body['debtors_count']);
        $this->assertEquals(120 + 240 + 360, $body['total_outstanding']);

        // Newest debt first: Ali bought today, Kareem last November.
        $this->assertSame('Ali', $body['customers'][0]['name']);
        $this->assertSame('Kareem', $body['customers'][1]['name']);

        $ali = $body['customers'][0];
        $this->assertEquals(360.0, $ali['balance']);
        $this->assertSame(2, $ali['open_sales_count']);
        $this->assertEquals(120.0, $ali['aging']['days_0_30']);
        $this->assertEquals(240.0, $ali['aging']['days_31_60']);
        $this->assertEquals(0.0, $ali['aging']['days_61_90']);
        $this->assertEquals(0.0, $ali['aging']['days_90_plus']);

        $this->assertEquals(360.0, $body['customers'][1]['aging']['days_90_plus']);

        // The shop-wide spread is the sum of the pages.
        $this->assertEquals(120.0, $body['aging']['days_0_30']);
        $this->assertEquals(240.0, $body['aging']['days_31_60']);
        $this->assertEquals(360.0, $body['aging']['days_90_plus']);

        // A settled customer is not a debtor.
        $this->assertSame($fresh['customer_id'], $ali['id']);
    }

    public function test_a_customer_who_owes_nothing_is_not_on_the_outstanding_list(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->onCredit($user, $product, 1);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", ['amount' => 120, 'method' => 'cash'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/outstanding')
            ->assertOk()
            ->assertJsonCount(0, 'customers')
            ->assertJsonPath('total_outstanding', 0);

        // Still on the khata, just with nothing against their name.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.balance', 0);
    }

    /* ----------------------------------------------------------- allocation */

    public function test_a_lump_sum_pays_off_the_oldest_sales_first(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $oldest = $this->onCredit($user, $product, 1);  // 120
        $middle = $this->onCredit($user, $product, 2);  // 240
        $newest = $this->onCredit($user, $product, 3);  // 360

        $this->backdate($oldest['id'], now()->subDays(20));
        $this->backdate($middle['id'], now()->subDays(10));

        // Rs 300 walks in against Rs 720 of debt.
        $body = $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$oldest['customer_id']}/payments", ['amount' => 300, 'method' => 'cash'])
            ->assertOk()
            ->json();

        $this->assertEquals(420.0, $body['customer']['balance']);
        $this->assertCount(2, $body['allocations']);

        // The oldest ticket is cleared outright, the next one part paid, and
        // the newest is not touched at all.
        $this->assertSame($oldest['id'], $body['allocations'][0]['sale_id']);
        $this->assertEquals(120.0, $body['allocations'][0]['amount']);
        $this->assertSame('paid', $body['allocations'][0]['payment_status']);

        $this->assertSame($middle['id'], $body['allocations'][1]['sale_id']);
        $this->assertEquals(180.0, $body['allocations'][1]['amount']);
        $this->assertSame('partial', $body['allocations'][1]['payment_status']);

        $this->assertSame('pending', Sale::findOrFail($newest['id'])->payment_status);

        // Real instalments, not a balance quietly adjusted: one row per ticket
        // the money landed on.
        $this->assertDatabaseCount('sale_payments', 2);
        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $oldest['id'],
            'amount' => 120,
            'method' => 'cash',
            'recorded_by' => $user->id,
        ]);
    }

    public function test_a_payment_that_clears_a_sale_flips_it_to_paid(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->onCredit($user, $product, 1, ['paid_amount' => 20]);

        $this->assertSame('partial', $sale['payment_status']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('customer.balance', 0)
            ->assertJsonPath('allocations.0.payment_status', 'paid')
            ->assertJsonPath('allocations.0.outstanding_amount', 0)
            ->assertJsonPath('message', 'Payment recorded. This khata is now clear.');

        $this->assertSame('paid', Sale::findOrFail($sale['id'])->payment_status);
    }

    public function test_paying_more_than_the_khata_owes_is_refused(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->onCredit($user, $product, 1);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", ['amount' => 120.01, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        // Nothing part-applied: the whole allocation rolls back together.
        $this->assertSame('0.00', Sale::findOrFail($sale['id'])->paid_amount);
        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_a_khata_with_nothing_open_takes_no_payment(): void
    {
        $user = $this->seller();
        $customer = Customer::create(['user_id' => $user->id, 'name' => 'Walk-in']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$customer->id}/payments", ['amount' => 50, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_khata_debt_cannot_be_settled_with_more_credit(): void
    {
        $user = $this->seller();
        $sale = $this->onCredit($user, $this->product($user), 1);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", ['amount' => 50, 'method' => 'credit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');

        // Refused at the door, so nothing was written: a settlement that moved
        // no money would still have shrunk the debt.
        $this->assertDatabaseCount('sale_payments', 0);
        $this->assertEquals(120.0, Customer::findOrFail($sale['customer_id'])->outstandingBalance());
    }

    /* --------------------------------------------------- part payments */

    public function test_a_part_payment_leaves_the_rest_on_the_page(): void
    {
        $user = $this->seller();
        $sale = $this->onCredit($user, $this->product($user), 2); // Rs 240

        // The customer has Rs 100 on them today and will bring the rest.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('customer.balance', 140)
            ->assertJsonPath('message', 'Payment recorded against 1 sale. Rs 140.00 still owed.');

        $this->assertSame('partial', Sale::findOrFail($sale['id'])->payment_status);

        // Nobody wrote a name down, and a blank one is stored as blank rather
        // than as the login that typed it — a guessed name reads as evidence.
        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $sale['id'],
            'amount' => 100,
            'received_by_name' => null,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", ['amount' => 140, 'method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('customer.balance', 0)
            ->assertJsonPath('message', 'Payment recorded. This khata is now clear.');

        $this->assertSame('paid', Sale::findOrFail($sale['id'])->payment_status);

        // Two visits, two instalments — the sale keeps both, not just the total.
        $this->assertDatabaseCount('sale_payments', 2);
    }

    public function test_the_hand_that_took_the_money_is_named_on_every_ticket_it_cleared(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $oldest = $this->onCredit($user, $product, 1); // 120
        $newest = $this->onCredit($user, $product, 2); // 240
        $this->backdate($oldest['id'], now()->subDays(9));

        // Rs 300 collected at the door by the delivery boy and handed to the
        // owner, who is the one logged in.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$oldest['customer_id']}/payments", [
                'amount' => 300,
                'method' => 'cash',
                'received_by_name' => 'Imran (delivery)',
            ])
            ->assertOk();

        // Both rows carry the name. Only tagging the first would leave the
        // second ticket looking as though the owner collected it himself.
        foreach ([$oldest['id'], $newest['id']] as $saleId) {
            $this->assertDatabaseHas('sale_payments', [
                'sale_id' => $saleId,
                'received_by_name' => 'Imran (delivery)',
                'recorded_by' => $user->id,
            ]);
        }

        $this->assertDatabaseCount('sale_payments', 2);
    }

    public function test_a_name_too_long_for_the_notebook_is_refused(): void
    {
        $user = $this->seller();
        $sale = $this->onCredit($user, $this->product($user), 1);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", [
                'amount' => 50,
                'method' => 'cash',
                'received_by_name' => str_repeat('a', 121),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('received_by_name');

        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_a_deposit_taken_at_the_till_records_who_took_it(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        // Over HTTP deliberately: the service could always carry the name, but
        // the endpoint's validation stripped it, so the feature existed and did
        // nothing. The till posts here, so this is the path that has to work.
        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
            'paid_amount' => 100,
            'deposit_method' => 'cash',
            'received_by_name' => 'Imran (counter)',
        ]);

        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $sale['id'],
            'amount' => 100,
            'received_by_name' => 'Imran (counter)',
        ]);
    }

    /* --------------------------------------------------------- credit limit */

    public function test_a_sale_past_the_credit_limit_is_refused(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $customer = Customer::create([
            'user_id' => $user->id,
            'name' => 'Bilal Traders',
            'phone' => '0300-1234567',
            'credit_limit' => 300,
        ]);

        $this->onCredit($user, $product, 2, ['customer_phone' => '0300-1234567']); // 240 of 300

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => 'credit',
                'customer_name' => 'Bilal Traders',
                'customer_phone' => '0300-1234567',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('credit_limit');

        // Refused before anything moved: one sale on the page, and the goods
        // are still on the shelf.
        $this->assertSame(1, $customer->sales()->count());
        $this->assertEquals(498, $product->fresh()->stock_quantity);
        $this->assertEquals(240.0, $customer->outstandingBalance());
    }

    public function test_a_deposit_can_bring_a_sale_back_under_the_limit(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        Customer::create([
            'user_id' => $user->id,
            'name' => 'Bilal Traders',
            'phone' => '0300-1234567',
            'credit_limit' => 300,
        ]);

        $this->onCredit($user, $product, 2, ['customer_phone' => '0300-1234567']);

        // The same Rs 120 sale goes through once Rs 80 is handed over, because
        // only the Rs 40 left unpaid is added to the khata.
        $sale = $this->onCredit($user, $product, 1, [
            'customer_phone' => '0300-1234567',
            'paid_amount' => 80,
        ]);

        $this->assertEquals(280.0, Customer::findOrFail($sale['customer_id'])->outstandingBalance());
    }

    public function test_a_sale_synced_from_an_offline_till_is_not_refused_by_the_limit(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        Customer::create([
            'user_id' => $user->id,
            'name' => 'Bilal Traders',
            'phone' => '0300-1234567',
            'credit_limit' => 100,
        ]);

        // The goods went out while the connection was down. Refusing the sync
        // would not un-give the credit, only lose the record of it.
        $sale = $this->onCredit($user, $product, 2, [
            'customer_phone' => '0300-1234567',
            'offline' => true,
            'client_uuid' => '1f6a4a90-6b1e-4f1f-9d1a-7f6b8c9d0e11',
        ]);

        $this->assertEquals(240.0, Customer::findOrFail($sale['customer_id'])->outstandingBalance());
    }

    public function test_a_page_with_no_limit_has_no_ceiling(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->onCredit($user, $product, 50); // Rs 6,000

        $customer = Customer::findOrFail($sale['customer_id']);

        $this->assertNull($customer->credit_limit);
        $this->assertNull($customer->creditAvailable());
        $this->assertEquals(6000.0, $customer->outstandingBalance());
    }

    /* --------------------------------------------------------------- ledger */

    public function test_the_ledger_reads_as_a_running_statement(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $first = $this->onCredit($user, $product, 2, ['paid_amount' => 40, 'deposit_method' => 'cash']);
        $this->backdate($first['id'], now()->subDays(5));

        $second = $this->onCredit($user, $product, 1);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$first['customer_id']}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertOk();

        $entries = $this->actingAs($user, 'sanctum')
            ->getJson("/api/customers/{$first['customer_id']}/ledger")
            ->assertOk()
            ->json('entries');

        // Sale 240, deposit 40, sale 120, payment 100.
        $this->assertCount(4, $entries);

        $this->assertSame('sale', $entries[0]['type']);
        $this->assertEquals(240.0, $entries[0]['charge']);
        $this->assertEquals(240.0, $entries[0]['balance']);

        // The till deposit shares its timestamp with the sale and still lands
        // after it, or the page would read as though money came in first.
        $this->assertSame('payment', $entries[1]['type']);
        $this->assertEquals(40.0, $entries[1]['credit']);
        $this->assertEquals(200.0, $entries[1]['balance']);

        $this->assertSame('sale', $entries[2]['type']);
        $this->assertEquals(320.0, $entries[2]['balance']);

        $this->assertSame('payment', $entries[3]['type']);
        $this->assertEquals(100.0, $entries[3]['credit']);
        $this->assertEquals(220.0, $entries[3]['balance']);

        // Every line names the ticket it moved, so the customer can be told
        // what their rupees just cleared — and the Rs 100 went on the older
        // ticket, not the one they had just walked out with.
        $this->assertSame($entries[0]['reference'], $entries[3]['reference']);
        $this->assertSame('cash', $entries[3]['method']);

        // The statement has to close on the same figure every other screen shows.
        $this->assertEquals(220.0, Customer::findOrFail($first['customer_id'])->outstandingBalance());
    }

    /**
     * The question the customer is standing at the counter asking: "I gave you
     * two hundred last week — what is left?"
     */
    public function test_the_payment_history_reads_newest_first_with_what_was_left(): void
    {
        // Pinned so the two payments land at known, different moments; the
        // history groups a lump sum on the stamp its allocations share.
        $this->travelTo(Carbon::create(2026, 6, 15, 10, 0));

        $user = $this->seller();
        $product = $this->product($user);

        $older = $this->onCredit($user, $product, 1); // 120
        $newer = $this->onCredit($user, $product, 2); // 240

        $this->backdate($older['id'], Carbon::create(2026, 6, 1, 9, 0));
        $this->backdate($newer['id'], Carbon::create(2026, 6, 8, 9, 0));

        $customerId = $older['customer_id'];

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$customerId}/payments", [
                'amount' => 200,
                'method' => 'cash',
                'received_by_name' => 'Imran (counter)',
            ])
            ->assertOk();

        $this->travelTo(Carbon::create(2026, 6, 15, 16, 30));

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$customerId}/payments", [
                'amount' => 60,
                'method' => 'easypaisa',
                'reference' => 'EP-4412',
                'received_by_name' => 'Sadia',
            ])
            ->assertOk();

        $payments = $this->actingAs($user, 'sanctum')
            ->getJson("/api/customers/{$customerId}/ledger")
            ->assertOk()
            ->json('payments');

        // Two visits, not the three rows they were stored as.
        $this->assertCount(2, $payments);

        // Newest first: the afternoon's EasyPaisa transfer.
        $this->assertEquals(60.0, $payments[0]['amount']);
        $this->assertSame('easypaisa', $payments[0]['method']);
        $this->assertSame('EP-4412', $payments[0]['reference']);
        $this->assertSame('Sadia', $payments[0]['received_by']);
        $this->assertSame($user->name, $payments[0]['recorded_by']);
        $this->assertEquals(100.0, $payments[0]['balance_after']);
        $this->assertCount(1, $payments[0]['allocations']);

        // The morning's Rs 200 was one handful of notes and reads as one line,
        // naming both tickets it was split across.
        $this->assertEquals(200.0, $payments[1]['amount']);
        $this->assertSame('Imran (counter)', $payments[1]['received_by']);
        $this->assertEquals(160.0, $payments[1]['balance_after']);

        $this->assertCount(2, $payments[1]['allocations']);
        $this->assertSame($older['id'], $payments[1]['allocations'][0]['sale_id']);
        $this->assertEquals(120.0, $payments[1]['allocations'][0]['amount']);
        $this->assertSame($newer['id'], $payments[1]['allocations'][1]['sale_id']);
        $this->assertEquals(80.0, $payments[1]['allocations'][1]['amount']);

        // The history has to close on the figure every other screen shows.
        $this->assertEquals(100.0, Customer::findOrFail($customerId)->outstandingBalance());
    }

    public function test_a_till_deposit_shows_in_the_payment_history(): void
    {
        $user = $this->seller();

        $sale = $this->onCredit($user, $this->product($user), 2, [
            'paid_amount' => 40,
            'deposit_method' => 'cash',
        ]);

        $payments = $this->actingAs($user, 'sanctum')
            ->getJson("/api/customers/{$sale['customer_id']}/ledger")
            ->assertOk()
            ->json('payments');

        // Money is money: what was handed over at the counter belongs in the
        // same history as what came back later, or the two never add up.
        $this->assertCount(1, $payments);
        $this->assertEquals(40.0, $payments[0]['amount']);
        $this->assertSame('Deposit taken at the till', $payments[0]['note']);
        $this->assertNull($payments[0]['received_by']);
        $this->assertEquals(200.0, $payments[0]['balance_after']);
    }

    /* -------------------------------------------------------------- refunds */

    public function test_returned_goods_come_off_what_is_owed(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->onCredit($user, $product, 3); // Rs 360
        $itemId = Sale::findOrFail($sale['id'])->items->first()->id;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/refund", [
                'items' => [['sale_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertOk();

        $customer = Customer::findOrFail($sale['customer_id']);
        $this->assertEquals(240.0, $customer->outstandingBalance());

        $entries = $this->actingAs($user, 'sanctum')
            ->getJson("/api/customers/{$customer->id}/ledger")
            ->assertOk()
            ->json('entries');

        $this->assertSame('refund', $entries[1]['type']);
        $this->assertEquals(120.0, $entries[1]['credit']);
        $this->assertEquals(240.0, $entries[1]['balance']);
    }

    public function test_a_refund_on_a_paid_up_sale_leaves_the_khata_clear_not_in_credit(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->onCredit($user, $product, 2); // Rs 240

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", ['amount' => 100, 'method' => 'cash'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/refund")
            ->assertOk();

        // The Rs 100 already handed over is owed back over the counter, not
        // carried on the khata — this app has no place to hold a customer
        // credit, so the page closes at zero rather than at minus 100.
        $customer = Customer::findOrFail($sale['customer_id']);
        $this->assertEquals(0.0, $customer->outstandingBalance());

        $entries = $this->actingAs($user, 'sanctum')
            ->getJson("/api/customers/{$customer->id}/ledger")
            ->assertOk()
            ->json('entries');

        $this->assertEquals(0.0, end($entries)['balance']);
    }

    /* ------------------------------------------------------------ day book */

    public function test_khata_cash_still_reaches_the_day_book(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 1000])
            ->assertSuccessful();

        // Yesterday's debt, collected today: the cash lands in today's drawer.
        $sale = $this->onCredit($user, $product, 2);
        $this->backdate($sale['id'], now()->subDay());

        // Part paid, and by a hand that is not the login — neither changes
        // where the notes end up.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", [
                'amount' => 150,
                'method' => 'cash',
                'received_by_name' => 'Imran (delivery)',
            ])
            ->assertOk();

        $cash = app(DayBookService::class)->cashPosition($user, now()->toDateString());

        $this->assertEquals(150.0, $cash['in']);
        $this->assertEquals(150.0, $cash['net']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/close', ['closing_amount' => 1150])
            ->assertSuccessful();

        $day = DayBalance::query()->firstOrFail();
        $this->assertEquals(1150.0, (float) $day->expected_amount);
    }

    public function test_a_wallet_payment_against_the_khata_stays_out_of_the_drawer(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->onCredit($user, $product, 2);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", [
                'amount' => 150,
                'method' => 'easypaisa',
                'reference' => 'EP-4412',
            ])
            ->assertOk();

        // The debt is smaller, but no notes changed hands.
        $this->assertEquals(90.0, Customer::findOrFail($sale['customer_id'])->outstandingBalance());
        $this->assertEquals(0.0, app(DayBookService::class)->cashPosition($user, now()->toDateString())['in']);
    }

    /* --------------------------------------------------------------- access */

    public function test_another_shops_khata_cannot_be_read_or_settled(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();

        $sale = $this->onCredit($theirs, $this->product($theirs), 2, ['customer_name' => 'Their customer']);
        $customerId = $sale['customer_id'];

        $this->actingAs($mine, 'sanctum')->getJson("/api/customers/{$customerId}")->assertForbidden();
        $this->actingAs($mine, 'sanctum')->getJson("/api/customers/{$customerId}/ledger")->assertForbidden();
        $this->actingAs($mine, 'sanctum')
            ->putJson("/api/customers/{$customerId}", ['name' => 'Mine now'])
            ->assertForbidden();
        $this->actingAs($mine, 'sanctum')
            ->postJson("/api/customers/{$customerId}/payments", ['amount' => 50, 'method' => 'cash'])
            ->assertForbidden();

        // The debt is untouched, and it never appeared on the other shop's list.
        $this->assertEquals(240.0, Customer::findOrFail($customerId)->outstandingBalance());

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/customers/outstanding')
            ->assertOk()
            ->assertJsonCount(0, 'customers')
            ->assertJsonPath('total_outstanding', 0);
    }

    public function test_one_shops_ali_is_not_another_shops_ali(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();

        $this->onCredit($mine, $this->product($mine), 1, ['customer_name' => 'Ali', 'customer_phone' => '0300-1111111']);
        $this->onCredit($theirs, $this->product($theirs), 2, ['customer_name' => 'Ali', 'customer_phone' => '0300-1111111']);

        $this->assertSame(2, Customer::query()->count());

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/customers/outstanding')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('total_outstanding', 120);
    }

    public function test_staff_work_their_shop_owners_khata(): void
    {
        $owner = $this->seller();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Corner Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $staff = User::factory()->create();
        $staff->assignRole(User::ROLE_STAFF, $shop->id);

        $sale = $this->onCredit($owner, $this->product($owner), 2);

        // The page belongs to the shop; the instalment records who took the money.
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/customers/{$sale['customer_id']}/payments", ['amount' => 240, 'method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('customer.balance', 0);

        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $sale['id'],
            'recorded_by' => $staff->id,
        ]);

        // And a sale the cashier rings up files under the owner's khata, not
        // under an empty notebook of their own.
        $staffSale = $this->onCredit($staff, $this->product($owner, ['name' => 'Biscuits', 'sku' => 'B-1']), 1);
        $this->assertSame($sale['customer_id'], $staffSale['customer_id']);
        $this->assertSame($owner->id, Customer::findOrFail($staffSale['customer_id'])->user_id);
    }

    /* ------------------------------------------------------- editing a page */

    public function test_a_page_can_be_opened_and_edited_by_hand(): void
    {
        $user = $this->seller();

        $created = $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers', [
                'name' => 'Kareem Store',
                'phone' => '0345-2222222',
                'address' => 'Shop 4, Main Bazaar',
                'credit_limit' => 5000,
                'notes' => 'Pays on the 1st',
            ])
            ->assertCreated()
            ->assertJsonPath('customer.credit_limit', 5000)
            ->assertJsonPath('customer.credit_available', 5000)
            ->assertJsonPath('customer.balance', 0)
            ->json('customer');

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/customers/{$created['id']}", [
                'name' => 'Kareem General Store',
                'phone' => '0345-2222222',
                'credit_limit' => null,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('customer.name', 'Kareem General Store')
            ->assertJsonPath('customer.credit_limit', null)
            ->assertJsonPath('customer.credit_available', null)
            ->assertJsonPath('customer.is_active', false);
    }

    public function test_two_pages_cannot_share_a_phone_number(): void
    {
        $user = $this->seller();

        Customer::create(['user_id' => $user->id, 'name' => 'Ali', 'phone' => '0300-1111111']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/customers', ['name' => 'Ali Raza', 'phone' => '0300-1111111'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
    }

    public function test_the_khata_can_be_searched_by_name_or_number(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->onCredit($user, $product, 1, ['customer_name' => 'Ali Raza', 'customer_phone' => '0300-1111111']);
        $this->onCredit($user, $product, 1, ['customer_name' => 'Kareem', 'customer_phone' => '0345-2222222']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers?search=raza')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Ali Raza');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers?search=0345')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Kareem');
    }

    public function test_the_whole_khata_is_paginated(): void
    {
        $user = $this->seller();

        foreach (range(1, 5) as $i) {
            Customer::create(['user_id' => $user->id, 'name' => "Customer {$i}"]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'customers')
            ->assertJsonPath('customers.0.name', 'Customer 1')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Customer 5')
            ->assertJsonPath('meta.current_page', 3);
    }

    /**
     * The debtor list pages, but the tiles above it do not: "the shop is owed
     * Rs 900 by three people" is a fact about the shop, not about page two.
     * The search reaches the whole list for the same reason — a name on page
     * three has to be findable from page one.
     */
    public function test_the_debtor_list_pages_while_its_totals_stay_shop_wide(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->onCredit($user, $product, 1, ['customer_name' => 'Ali Raza', 'customer_phone' => '0300-1111111']);
        $this->onCredit($user, $product, 2, ['customer_name' => 'Kareem', 'customer_phone' => '0345-2222222']);
        $this->onCredit($user, $product, 3, ['customer_name' => 'Sadiq', 'customer_phone' => '0321-3333333']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/outstanding?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'customers')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('debtors_count', 3)
            ->assertJsonPath('total_outstanding', 120 + 240 + 360);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/outstanding?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('total_outstanding', 120 + 240 + 360);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/outstanding?search=raza')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Ali Raza')
            ->assertJsonPath('meta.total', 1)
            // Filtering the list does not restate what the shop is owed.
            ->assertJsonPath('total_outstanding', 120 + 240 + 360);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/outstanding?search=0345')
            ->assertOk()
            ->assertJsonCount(1, 'customers')
            ->assertJsonPath('customers.0.name', 'Kareem');
    }

    /**
     * The per-sale settle endpoint had the same stripped-field problem as the
     * till's deposit. A khata screen settles a whole customer, but the sales
     * list settles one ticket, and both have to name who took the money.
     */
    public function test_a_payment_against_one_sale_records_who_took_it(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'payment_method' => 'credit',
            'customer_name' => 'Ali Raza',
            'paid_amount' => 0,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", [
                'amount' => 100,
                'method' => 'cash',
                'received_by_name' => 'Bilal (delivery)',
            ])
            ->assertOk()
            ->assertJsonPath('sale.payment_status', 'partial');

        $this->assertSame(
            'Bilal (delivery)',
            Sale::query()->find($sale['id'])->payments()->latest('id')->value('received_by_name'),
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

/**
 * Sales reporting (filters, profit, stats) and the credit / part-payment
 * lifecycle that sits behind it.
 */
class SaleReportingTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

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
            'stock_quantity' => 100,
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

    /* --------------------------------------------------------- credit sales */

    public function test_a_credit_sale_goes_out_unpaid_but_still_moves_stock(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 10]);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
            'customer_phone' => '0300-1234567',
        ]);

        $this->assertSame('completed', $sale['status']);
        $this->assertSame('pending', $sale['payment_status']);
        $this->assertEquals(0.0, $sale['paid_amount']);
        $this->assertEquals(240.0, $sale['outstanding_amount']);
        $this->assertSame('Bilal Traders', $sale['customer_name']);

        // The goods left the shop — only the money is outstanding.
        $this->assertEquals(8, $product->fresh()->stock_quantity);
    }

    public function test_a_credit_sale_without_a_customer_name_is_rejected(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => 'credit',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('customer_name');

        // Nothing partially applied — an uncollectable debt is never recorded.
        $this->assertDatabaseCount('sales', 0);
        $this->assertEquals(100, $product->fresh()->stock_quantity);
    }

    public function test_a_deposit_at_the_till_opens_the_sale_as_partially_paid(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
            'paid_amount' => 100,
            'deposit_method' => 'easypaisa',
        ]);

        $this->assertSame('partial', $sale['payment_status']);
        $this->assertEquals(140.0, $sale['outstanding_amount']);

        // The deposit is the first instalment, so paid_amount adds up from the
        // payment history rather than appearing out of nowhere.
        $this->assertCount(1, $sale['payments']);
        $this->assertEquals(100.0, $sale['payments'][0]['amount']);
        $this->assertSame('easypaisa', $sale['payments'][0]['method']);
    }

    public function test_a_deposit_cannot_exceed_the_sale_total(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/sales', [
                'items' => [['product_id' => $product->id, 'quantity' => 1]],
                'payment_method' => 'credit',
                'customer_name' => 'Bilal Traders',
                'paid_amount' => 500,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('paid_amount');
    }

    public function test_a_wallet_sale_keeps_its_transaction_reference_and_settles_at_the_till(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'jazzcash',
            'payment_reference' => 'JC-88213',
        ]);

        $this->assertSame('paid', $sale['payment_status']);
        $this->assertEquals(120.0, $sale['paid_amount']);
        $this->assertEquals(0.0, $sale['outstanding_amount']);
        $this->assertSame('JC-88213', $sale['payment_reference']);
        $this->assertSame([], $sale['payments']);
    }

    /* ----------------------------------------------------------- settlement */

    public function test_instalments_move_the_sale_from_pending_to_partial_to_paid(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
        ]);

        $this->assertSame('pending', $sale['payment_status']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 90, 'method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('sale.payment_status', 'partial')
            ->assertJsonPath('sale.paid_amount', 90)
            ->assertJsonPath('sale.outstanding_amount', 150);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", [
                'amount' => 150,
                'method' => 'bank_transfer',
                'reference' => 'HBL-771',
                'note' => 'Final settlement',
            ])
            ->assertOk()
            ->assertJsonPath('sale.payment_status', 'paid')
            ->assertJsonPath('sale.paid_amount', 240)
            ->assertJsonPath('sale.outstanding_amount', 0)
            ->assertJsonCount(2, 'sale.payments')
            ->assertJsonPath('sale.payments.1.reference', 'HBL-771');

        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $sale['id'],
            'recorded_by' => $user->id,
            'method' => 'bank_transfer',
        ]);
    }

    public function test_a_payment_larger_than_the_outstanding_balance_is_rejected(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
            'paid_amount' => 20,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 100.01, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        // Neither the balance nor the history moved.
        $this->assertSame('20.00', Sale::findOrFail($sale['id'])->paid_amount);
        $this->assertDatabaseCount('sale_payments', 1);
    }

    public function test_a_non_positive_payment_is_rejected(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
        ]);

        foreach ([0, -50] as $amount) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/sales/{$sale['id']}/payments", ['amount' => $amount, 'method' => 'cash'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('amount');
        }

        $this->assertDatabaseCount('sale_payments', 0);
    }

    public function test_a_settled_sale_takes_no_further_payments(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        // Already paid in full at the till.
        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 10, 'method' => 'cash'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    }

    public function test_a_debt_cannot_be_settled_with_more_credit(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 50, 'method' => 'credit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');
    }

    public function test_refunding_a_credit_sale_stops_chasing_the_returned_goods(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/refund")
            ->assertOk()
            ->assertJsonPath('sale.status', 'refunded')
            ->assertJsonPath('sale.payment_status', 'paid')
            ->assertJsonPath('sale.outstanding_amount', 0);
    }

    /* --------------------------------------------------------------- access */

    public function test_another_shops_sale_cannot_be_read_or_settled(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();

        $sale = $this->sell($theirs, [
            'items' => [['product_id' => $this->product($theirs)->id, 'quantity' => 1]],
            'payment_method' => 'credit',
            'customer_name' => 'Their customer',
        ]);

        $this->actingAs($mine, 'sanctum')
            ->getJson("/api/sales/{$sale['id']}")
            ->assertForbidden();

        $this->actingAs($mine, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 50, 'method' => 'cash'])
            ->assertForbidden();

        // The debt is untouched, and it never showed up in the other shop's list.
        $this->assertSame('0.00', Sale::findOrFail($sale['id'])->paid_amount);
        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/sales?payment_status=pending')
            ->assertOk()
            ->assertJsonCount(0, 'sales');
    }

    public function test_staff_settle_against_their_shop_owners_sale(): void
    {
        $owner = $this->seller();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Corner Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $staff = User::factory()->create();
        $staff->assignRole(User::ROLE_STAFF, $shop->id);

        $sale = $this->sell($owner, [
            'items' => [['product_id' => $this->product($owner)->id, 'quantity' => 1]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
        ]);

        // The takings belong to the shop, but the instalment records who took it.
        $this->actingAs($staff, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 120, 'method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('sale.payment_status', 'paid');

        $this->assertDatabaseHas('sale_payments', [
            'sale_id' => $sale['id'],
            'recorded_by' => $staff->id,
        ]);
    }

    /* --------------------------------------------------------------- profit */

    public function test_profit_is_line_margin_net_of_refunded_units(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['price' => 120, 'cost' => 90]);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 3]],
        ]);

        // 3 x (120 - 90).
        $this->assertEquals(90.0, $sale['profit']['amount']);
        $this->assertTrue($sale['profit']['is_complete']);

        $itemId = Sale::findOrFail($sale['id'])->items->first()->id;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/refund", [
                'items' => [['sale_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('sale.profit.amount', 60);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales')
            ->assertOk()
            ->assertJsonPath('sales.0.profit.amount', 60);
    }

    public function test_a_line_with_no_recorded_cost_is_reported_as_unknown_not_as_pure_profit(): void
    {
        $user = $this->seller();
        $costed = $this->product($user, ['price' => 120, 'cost' => 90]);
        $unknown = $this->product($user, ['name' => 'Loose sweets', 'price' => 200, 'cost' => null, 'sku' => 'LS-1']);

        $sale = $this->sell($user, [
            'items' => [
                ['product_id' => $costed->id, 'quantity' => 1],
                ['product_id' => $unknown->id, 'quantity' => 2],
            ],
        ]);

        // Only the costed line contributes; the other 400 is takings we cannot
        // attribute, and saying so beats reporting 430 of imaginary profit.
        $this->assertEquals(30.0, $sale['profit']['amount']);
        $this->assertFalse($sale['profit']['is_complete']);
        $this->assertEquals(400.0, $sale['profit']['unknown_cost_revenue']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales/stats')
            ->assertOk()
            ->assertJsonPath('stats.today.profit.amount', 30)
            ->assertJsonPath('stats.today.profit.is_complete', false)
            ->assertJsonPath('stats.today.profit.unknown_cost_revenue', 400);
    }

    /* ---------------------------------------------------------------- stats */

    public function test_stats_report_takings_and_profit_for_each_window(): void
    {
        // Wednesday 20 May 2026 — pinned so every window boundary is known and
        // the assertions do not shift with the day the suite happens to run.
        $this->travelTo(Carbon::create(2026, 5, 20, 10, 0));

        $user = $this->seller();
        $product = $this->product($user, ['price' => 120, 'cost' => 90, 'stock_quantity' => 500]);

        $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 2]]]);
        $this->backdate($user, $product, 1, Carbon::create(2026, 5, 1, 9, 0));
        $this->backdate($user, $product, 4, Carbon::create(2026, 4, 10, 9, 0));

        $stats = $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales/stats')
            ->assertOk()
            ->json('stats');

        $this->assertEquals(240.0, $stats['today']['sales_total']);
        $this->assertEquals(60.0, $stats['today']['profit']['amount']);
        $this->assertSame(1, $stats['today']['sales_count']);

        // 1 May is this month but two weeks before this one.
        $this->assertEquals(240.0, $stats['this_week']['sales_total']);
        $this->assertEquals(360.0, $stats['this_month']['sales_total']);
        $this->assertEquals(90.0, $stats['this_month']['profit']['amount']);

        $this->assertEquals(480.0, $stats['last_month']['sales_total']);
        $this->assertEquals(120.0, $stats['last_month']['profit']['amount']);

        $this->assertEquals(840.0, $stats['this_year']['sales_total']);
        $this->assertEquals(210.0, $stats['this_year']['profit']['amount']);
    }

    public function test_the_stats_week_runs_monday_to_sunday(): void
    {
        // Thursday 21 May 2026: the week is Mon 18th to Sun 24th.
        $this->travelTo(Carbon::create(2026, 5, 21, 12, 0));

        $user = $this->seller();
        $product = $this->product($user, ['price' => 120, 'cost' => 90, 'stock_quantity' => 500]);

        $this->backdate($user, $product, 1, Carbon::create(2026, 5, 18, 9, 0));
        $this->backdate($user, $product, 5, Carbon::create(2026, 5, 17, 18, 0));

        $stats = $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales/stats')
            ->assertOk()
            ->json('stats');

        // The Sunday sale belongs to the week before, not this one.
        $this->assertEquals(120.0, $stats['this_week']['sales_total']);
        $this->assertSame(1, $stats['this_week']['sales_count']);
        $this->assertSame('2026-05-18 00:00:00', $stats['this_week']['from']);
        $this->assertSame('2026-05-24 23:59:59', $stats['this_week']['to']);
    }

    public function test_stats_ignore_another_shops_takings(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();

        $this->sell($theirs, [
            'items' => [['product_id' => $this->product($theirs)->id, 'quantity' => 2]],
        ]);

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/sales/stats')
            ->assertOk()
            ->assertJsonPath('stats.today.sales_total', 0)
            ->assertJsonPath('stats.today.profit.amount', 0);
    }

    public function test_a_refunded_sale_nets_out_of_the_stats(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 2]]]);
        $this->actingAs($user, 'sanctum')->postJson("/api/sales/{$sale['id']}/refund")->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales/stats')
            ->assertOk()
            ->assertJsonPath('stats.today.sales_total', 0)
            ->assertJsonPath('stats.today.sales_count', 0)
            ->assertJsonPath('stats.today.profit.amount', 0);
    }

    /* -------------------------------------------------------------- filters */

    public function test_the_period_filter_selects_today_this_week_and_this_month(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 500]);

        // Mid-week so there is room either side inside the same month.
        $this->travelTo(now()->startOfMonth()->addDays(14)->startOfWeek(Carbon::MONDAY)->addDays(3)->setTime(12, 0));

        $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->backdate($user, $product, 1, now()->startOfWeek(Carbon::MONDAY)->subDays(2)->setTime(10, 0));
        $this->backdate($user, $product, 1, now()->subMonthNoOverflow()->startOfMonth()->addDays(3));

        $count = fn (string $query) => $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales?'.$query)
            ->assertOk()
            ->json('meta.total');

        $this->assertSame(1, $count('period=today'));
        $this->assertSame(1, $count('period=week'));
        $this->assertSame(2, $count('period=month'));
        $this->assertSame(3, $count(''));
    }

    public function test_a_custom_period_needs_both_ends_and_filters_between_them(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['stock_quantity' => 500]);

        $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->backdate($user, $product, 1, now()->subDays(10));

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/sales?period=custom')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from', 'date_to']);

        $from = now()->subDays(12)->toDateString();
        $to = now()->subDays(8)->toDateString();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/sales?period=custom&date_from={$from}&date_to={$to}")
            ->assertOk()
            ->assertJsonCount(1, 'sales');
    }

    public function test_the_category_filter_returns_sales_containing_that_category(): void
    {
        $user = $this->seller();
        $drinks = ProductCategory::create(['user_id' => $user->id, 'name' => 'Drinks', 'slug' => 'drinks']);
        $snacks = ProductCategory::create(['user_id' => $user->id, 'name' => 'Snacks', 'slug' => 'snacks']);

        $cola = $this->product($user, ['product_category_id' => $drinks->id]);
        $chips = $this->product($user, ['name' => 'Chips', 'sku' => 'CH-1', 'product_category_id' => $snacks->id]);

        // A mixed ticket must appear once under either category, not twice.
        $this->sell($user, [
            'items' => [
                ['product_id' => $cola->id, 'quantity' => 1],
                ['product_id' => $chips->id, 'quantity' => 1],
            ],
        ]);
        $this->sell($user, ['items' => [['product_id' => $chips->id, 'quantity' => 1]]]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/sales?category_id={$drinks->id}")
            ->assertOk()
            ->assertJsonCount(1, 'sales');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/sales?category_id={$snacks->id}")
            ->assertOk()
            ->assertJsonCount(2, 'sales');
    }

    public function test_the_payment_status_filter_finds_what_is_still_owed(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 1]]]);
        $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
        ]);
        $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'credit',
            'customer_name' => 'Kareem Store',
            'paid_amount' => 20,
        ]);

        $count = fn (string $status) => $this->actingAs($user, 'sanctum')
            ->getJson("/api/sales?payment_status={$status}")
            ->assertOk()
            ->json('meta.total');

        $this->assertSame(1, $count('paid'));
        $this->assertSame(1, $count('pending'));
        $this->assertSame(1, $count('partial'));
    }

    public function test_sale_detail_carries_the_customer_balance_and_payment_history(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
            'customer_phone' => '0300-1234567',
            'paid_amount' => 40,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 60, 'method' => 'cash'])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/sales/{$sale['id']}")
            ->assertOk()
            ->assertJsonPath('sale.payment_status', 'partial')
            ->assertJsonPath('sale.paid_amount', 100)
            ->assertJsonPath('sale.outstanding_amount', 140)
            ->assertJsonPath('sale.customer_phone', '0300-1234567')
            ->assertJsonPath('sale.items.0.unit_cost', 90)
            ->assertJsonPath('sale.profit.amount', 60)
            ->assertJsonCount(2, 'sale.payments');
    }

    /**
     * Ring up a sale and move it back in time. `sold_at` on the endpoint only
     * accepts the last 30 days, which is not far enough for the month and year
     * windows, so history is planted directly.
     */
    private function backdate(User $user, Product $product, int $quantity, Carbon $soldAt): Sale
    {
        $sale = Sale::findOrFail($this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
        ])['id']);

        $sale->forceFill(['sold_at' => $soldAt])->save();

        return $sale;
    }
}

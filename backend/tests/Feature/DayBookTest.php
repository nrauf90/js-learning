<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\DayBalance;
use App\Models\ExpenseCategory;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\User;
use App\Services\Pos\DayBookService;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class DayBookTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpenseCategorySeeder::class);
    }

    private function cashier(): User
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        return $user;
    }

    /**
     * Sales are written straight to the table rather than rung up through the
     * till: what this suite cares about is how a row affects the drawer, not
     * how it got there.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function sale(User $user, array $attributes = []): Sale
    {
        return Sale::create(array_merge([
            'user_id' => $user->id,
            'subtotal' => 1000,
            'discount_amount' => 0,
            'total' => 1000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'paid_amount' => 1000,
            'status' => 'completed',
            'sold_at' => now(),
        ], $attributes));
    }

    /* ------------------------------------------------------------ access */

    public function test_guest_cannot_reach_the_day_book(): void
    {
        $this->getJson('/api/day-book/current')->assertUnauthorized();
        $this->postJson('/api/day-book/open', ['opening_amount' => 100])->assertUnauthorized();
    }

    public function test_day_book_is_behind_the_subscription_gate(): void
    {
        $user = User::factory()->create();
        $this->expireTrial($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertStatus(402)
            ->assertJsonPath('code', 'trial_expired');
    }

    /* -------------------------------------------------------- open/close */

    public function test_till_starts_the_day_closed(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('is_open', false)
            ->assertJsonPath('is_closed', false)
            ->assertJsonPath('day_book', null)
            ->assertJsonPath('date', now()->toDateString());
    }

    public function test_user_can_open_and_then_close_a_day(): void
    {
        $user = $this->cashier();
        $this->sale($user, ['total' => 2500, 'paid_amount' => 2500]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 5000])
            ->assertCreated()
            ->assertJsonPath('already_open', false)
            ->assertJsonPath('day_book.opening_amount', 5000)
            ->assertJsonPath('day_book.is_open', true)
            ->assertJsonPath('day_book.opened_by', $user->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('is_open', true)
            ->assertJsonPath('opening_amount', 5000)
            ->assertJsonPath('expected_cash', 7500)
            ->assertJsonPath('cash_takings', 2500)
            ->assertJsonPath('sales_total', 2500)
            ->assertJsonPath('sales_count', 1);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/close', ['closing_amount' => 7500])
            ->assertOk()
            ->assertJsonPath('day_book.closing_amount', 7500)
            ->assertJsonPath('day_book.expected_amount', 7500)
            ->assertJsonPath('day_book.variance', 0)
            ->assertJsonPath('day_book.is_open', false)
            ->assertJsonPath('day_book.closed_by', $user->id);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('is_open', false)
            ->assertJsonPath('is_closed', true);
    }

    public function test_reopening_an_open_day_returns_the_same_row(): void
    {
        $user = $this->cashier();

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 5000])
            ->assertCreated();

        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 9999])
            ->assertOk()
            ->assertJsonPath('already_open', true);

        $this->assertSame($first->json('day_book.id'), $second->json('day_book.id'));

        // The second float must not overwrite the first, or the morning's count
        // is lost the moment someone re-opens the till screen.
        $second->assertJsonPath('day_book.opening_amount', 5000);
        $this->assertDatabaseCount('day_balances', 1);
        $this->assertSame(1, CashEntry::where('user_id', $user->id)->count());
    }

    public function test_closing_a_day_that_was_never_opened_is_rejected(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/close', ['closing_amount' => 5000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['closing_amount']);

        $this->assertDatabaseCount('day_balances', 0);
    }

    public function test_a_day_cannot_be_closed_twice(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 1000]);
        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/close', ['closing_amount' => 1200])->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/close', ['closing_amount' => 9999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['closing_amount']);

        $day = DayBalance::firstOrFail();
        $this->assertSame('1200.00', $day->closing_amount);
        $this->assertSame(1, CashEntry::where('type', 'income')->count());
    }

    public function test_a_closed_day_cannot_be_reopened(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 1000]);
        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/close', ['closing_amount' => 1000]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 2000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['opening_amount']);
    }

    /* --------------------------------------------------------- variance */

    public function test_variance_is_the_gap_between_counted_and_expected(): void
    {
        $user = $this->cashier();
        $this->sale($user, ['total' => 1500.25, 'paid_amount' => 1500.25]);

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 500]);

        // Expected 2000.25, counted 1950.25 — the drawer is 50 short.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/close', ['closing_amount' => 1950.25])
            ->assertOk()
            ->assertJsonPath('day_book.expected_amount', 2000.25)
            ->assertJsonPath('day_book.variance', -50);

        // A refund logged after the close must not rewrite the frozen figure.
        $this->sale($user, [
            'total' => 400,
            'paid_amount' => 400,
            'refunded_amount' => 400,
            'refunded_at' => now(),
            'status' => 'refunded',
        ]);

        $this->assertSame('2000.25', DayBalance::firstOrFail()->expected_amount);
    }

    public function test_variance_is_positive_when_the_drawer_holds_more_than_expected(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 1000]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/close', ['closing_amount' => 1075.5])
            ->assertOk()
            ->assertJsonPath('day_book.variance', 75.5);
    }

    /* ------------------------------------------------------ expected cash */

    public function test_expected_cash_ignores_non_cash_payment_methods(): void
    {
        $user = $this->cashier();

        $this->sale($user, ['total' => 1000, 'paid_amount' => 1000]);
        $this->sale($user, ['total' => 2000, 'paid_amount' => 2000, 'payment_method' => 'card']);
        $this->sale($user, ['total' => 3000, 'paid_amount' => 3000, 'payment_method' => 'easypaisa']);
        $this->sale($user, ['total' => 4000, 'paid_amount' => 4000, 'payment_method' => 'jazzcash']);
        $this->sale($user, ['total' => 5000, 'paid_amount' => 5000, 'payment_method' => 'bank_transfer']);

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 500]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('cash_takings', 1000)
            ->assertJsonPath('expected_cash', 1500)
            // Takings are every method; only the drawer is cash-only.
            ->assertJsonPath('sales_total', 15000);
    }

    public function test_a_sale_on_credit_puts_nothing_in_the_drawer(): void
    {
        $user = $this->cashier();

        $this->sale($user, [
            'total' => 2000,
            'payment_method' => 'credit',
            'payment_status' => 'pending',
            'paid_amount' => 0,
            'customer_name' => 'Bilal',
        ]);

        // Half down in cash, half still owed.
        $partial = $this->sale($user, [
            'total' => 1000,
            'payment_method' => 'cash',
            'payment_status' => 'partial',
            'paid_amount' => 400,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 0]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('cash_takings', 400)
            ->assertJsonPath('expected_cash', 400)
            ->assertJsonPath('sales_total', 3000);

        $this->assertSame(1000.0, (float) $partial->total);
    }

    public function test_cash_collected_against_an_older_credit_sale_lands_in_todays_drawer(): void
    {
        $user = $this->cashier();

        $sale = $this->sale($user, [
            'total' => 5000,
            'payment_method' => 'credit',
            'payment_status' => 'partial',
            'paid_amount' => 2000,
            'sold_at' => now()->subDays(3),
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'recorded_by' => $user->id,
            'amount' => 2000,
            'method' => 'cash',
            'paid_at' => now(),
        ]);

        // A card instalment settles elsewhere, so it never reaches the drawer.
        SalePayment::create([
            'sale_id' => $sale->id,
            'recorded_by' => $user->id,
            'amount' => 1500,
            'method' => 'card',
            'paid_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 100]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('cash_takings', 2000)
            ->assertJsonPath('expected_cash', 2100)
            // The sale itself was rung up three days ago.
            ->assertJsonPath('sales_total', 0);
    }

    public function test_a_same_day_instalment_is_not_counted_twice(): void
    {
        $user = $this->cashier();

        $sale = $this->sale($user, [
            'total' => 1000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
            'paid_amount' => 1000,
        ]);

        SalePayment::create([
            'sale_id' => $sale->id,
            'recorded_by' => $user->id,
            'amount' => 1000,
            'method' => 'cash',
            'paid_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 0]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('cash_takings', 1000);
    }

    public function test_cash_refunds_come_back_out_of_the_drawer(): void
    {
        $user = $this->cashier();

        $this->sale($user, ['total' => 2000, 'paid_amount' => 2000]);
        $this->sale($user, [
            'total' => 800,
            'paid_amount' => 800,
            'refunded_amount' => 800,
            'refunded_at' => now(),
            'status' => 'refunded',
        ]);

        // Refunding a card sale gives the money back to the card, not the till.
        $this->sale($user, [
            'total' => 600,
            'paid_amount' => 600,
            'payment_method' => 'card',
            'refunded_amount' => 600,
            'refunded_at' => now(),
            'status' => 'refunded',
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 1000]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('cash_takings', 2800)
            ->assertJsonPath('cash_refunds', 800)
            ->assertJsonPath('expected_cash', 3000);
    }

    public function test_yesterdays_sales_do_not_move_todays_drawer(): void
    {
        $user = $this->cashier();
        $this->sale($user, ['total' => 4000, 'paid_amount' => 4000, 'sold_at' => now()->subDay()]);

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 250]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('expected_cash', 250);
    }

    /* ------------------------------------------------------- cash ledger */

    public function test_opening_and_closing_post_to_the_cash_ledger(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 1500]);
        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/close', ['closing_amount' => 4200]);

        $day = DayBalance::firstOrFail();

        $opening = CashEntry::findOrFail($day->opening_entry_id);
        $this->assertSame('expense', $opening->type);
        $this->assertSame(DayBookService::FLOAT_CATEGORY_SLUG, $opening->category->slug);
        $this->assertSame('1500.00', $opening->amount);

        $closing = CashEntry::findOrFail($day->closing_entry_id);
        $this->assertSame('income', $closing->type);
        $this->assertSame(DayBookService::CLOSE_CATEGORY_SLUG, $closing->category->slug);
        $this->assertSame('4200.00', $closing->amount);

        // Float out, drawer in — the day nets to the cash it actually gained.
        $this->assertSame(2700.0, (float) $closing->amount - (float) $opening->amount);
    }

    public function test_an_empty_drawer_posts_no_ledger_entry(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 0])
            ->assertCreated()
            ->assertJsonPath('day_book.opening_entry_id', null);

        $this->assertSame(0, CashEntry::count());
    }

    public function test_day_book_categories_are_not_seeded_until_a_day_is_opened(): void
    {
        $user = $this->cashier();

        $this->assertDatabaseMissing('expense_categories', [
            'slug' => DayBookService::FLOAT_CATEGORY_SLUG,
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 100]);

        $this->assertDatabaseHas('expense_categories', [
            'slug' => DayBookService::FLOAT_CATEGORY_SLUG,
        ]);
    }

    /**
     * The float and close categories are the day book's own bookkeeping. They
     * have to exist as real rows for the ledger entries to point at, but
     * offering them in the manual picker would let someone hand-file an expense
     * into the drawer reconciliation and throw the day's variance out.
     */
    public function test_day_book_categories_never_reach_the_manual_picker(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 100]);
        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/close', ['closing_amount' => 100]);

        $categories = $this->actingAs($user, 'sanctum')
            ->getJson('/api/categories')
            ->assertOk();

        $categories->assertJsonMissing(['slug' => DayBookService::FLOAT_CATEGORY_SLUG]);
        $categories->assertJsonMissing(['slug' => DayBookService::CLOSE_CATEGORY_SLUG]);

        // Both really were created — this is a visibility rule, not an absence.
        $this->assertDatabaseHas('expense_categories', ['slug' => DayBookService::FLOAT_CATEGORY_SLUG]);
        $this->assertDatabaseHas('expense_categories', ['slug' => DayBookService::CLOSE_CATEGORY_SLUG]);

        // Guards against the two slug lists drifting apart.
        $this->assertContains(DayBookService::FLOAT_CATEGORY_SLUG, ExpenseCategory::INTERNAL_SLUGS);
        $this->assertContains(DayBookService::CLOSE_CATEGORY_SLUG, ExpenseCategory::INTERNAL_SLUGS);
    }

    /* ------------------------------------------------------------ history */

    public function test_history_is_paginated_newest_first(): void
    {
        $user = $this->cashier();

        foreach (range(1, 5) as $i) {
            DayBalance::create([
                'user_id' => $user->id,
                'business_date' => now()->subDays($i)->toDateString(),
                'opening_amount' => 100 * $i,
                'closing_amount' => 100 * $i + 50,
                'expected_amount' => 100 * $i + 60,
                'opened_at' => now()->subDays($i),
                'closed_at' => now()->subDays($i)->addHours(9),
                'opened_by' => $user->id,
                'closed_by' => $user->id,
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/day-book?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'days')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('days.0.business_date', now()->subDay()->toDateString())
            ->assertJsonPath('days.0.variance', -10)
            ->assertJsonPath('days.0.opened_by_name', $user->name);
    }

    /* ------------------------------------------------------ authorization */

    public function test_a_user_cannot_read_or_close_another_shops_day_book(): void
    {
        $owner = $this->cashier();
        $intruder = $this->cashier();

        $this->actingAs($owner, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 5000])
            ->assertCreated();

        $ownersDay = DayBalance::firstOrFail();

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('is_open', false)
            ->assertJsonPath('day_book', null);

        $this->actingAs($intruder, 'sanctum')
            ->getJson('/api/day-book')
            ->assertOk()
            ->assertJsonCount(0, 'days');

        // Closing "today" must not reach across to the other shop's open day.
        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/day-book/close', ['closing_amount' => 5000])
            ->assertUnprocessable();

        $this->assertTrue($ownersDay->fresh()->isOpen());
        $this->assertTrue(Gate::forUser($intruder)->denies('close', $ownersDay));
        $this->assertTrue(Gate::forUser($intruder)->denies('view', $ownersDay));
        $this->assertTrue(Gate::forUser($owner)->allows('close', $ownersDay));
    }

    public function test_staff_operate_their_shop_owners_day_book(): void
    {
        $owner = $this->cashier();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Corner Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $staff = User::factory()->create();
        $staff->assignRole(User::ROLE_STAFF, $shop->id);

        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 3000])
            ->assertCreated()
            ->assertJsonPath('day_book.opened_by', $staff->id)
            ->assertJsonPath('day_book.opened_by_name', $staff->name);

        $day = DayBalance::firstOrFail();
        $this->assertSame($owner->id, $day->user_id);
        $this->assertSame($shop->id, $day->shop_id);

        // The owner sees the same day book the staff member opened.
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/day-book/current')
            ->assertOk()
            ->assertJsonPath('is_open', true)
            ->assertJsonPath('day_book.id', $day->id);

        // And the float landed in the shop's ledger, not the staff member's.
        $this->assertSame($owner->id, CashEntry::firstOrFail()->user_id);
    }

    /* ------------------------------------------------------------ guards */

    public function test_business_date_cannot_be_spoofed_from_request_input(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', [
                'opening_amount' => 100,
                'business_date' => '2020-01-01',
                'opened_at' => '2020-01-01 08:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('day_book.business_date', now()->toDateString());
    }

    public function test_opening_amount_is_validated(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['opening_amount']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['opening_amount']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 9999999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['opening_amount']);
    }

    public function test_one_day_book_per_shop_per_day_is_enforced_by_the_database(): void
    {
        $user = $this->cashier();

        $this->actingAs($user, 'sanctum')->postJson('/api/day-book/open', ['opening_amount' => 100]);

        // The service resolves concurrent opens by catching exactly this.
        $this->expectException(UniqueConstraintViolationException::class);

        DayBalance::create([
            'user_id' => $user->id,
            'business_date' => now()->toDateString(),
            'opening_amount' => 200,
            'opened_at' => now(),
            'opened_by' => $user->id,
        ]);
    }
}

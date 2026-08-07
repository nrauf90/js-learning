<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

/**
 * The two questions the reports screen exists to answer: what the shop earned,
 * and where that money is standing now.
 */
class ProfitReportTest extends TestCase
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
            'stock_quantity' => 100,
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

    private function expense(User $user, string $slug, float $amount, ?string $date = null): CashEntry
    {
        return CashEntry::create([
            'user_id' => $user->id,
            'category_id' => ExpenseCategory::where('slug', $slug)->firstOrFail()->id,
            'type' => 'expense',
            'amount' => $amount,
            'entry_date' => $date ?? now()->toDateString(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function profitReport(User $user, string $query = ''): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/profit'.($query ? '?'.$query : ''))
            ->assertOk()
            ->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function cashReport(User $user, string $query = ''): array
    {
        return $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/cash-position'.($query ? '?'.$query : ''))
            ->assertOk()
            ->json();
    }

    /* ----------------------------------------------------------- gross profit */

    public function test_gross_profit_is_sales_less_what_the_goods_cost(): void
    {
        $user = $this->seller();
        $atta = $this->product($user, ['name' => 'Atta 10kg', 'price' => 1400, 'cost' => 1250, 'sku' => 'A-1']);
        $cola = $this->product($user, ['price' => 120, 'cost' => 90, 'sku' => 'C-1']);

        $this->sell($user, [
            'items' => [
                ['product_id' => $atta->id, 'quantity' => 2],
                ['product_id' => $cola->id, 'quantity' => 5],
            ],
        ]);

        $report = $this->profitReport($user);

        // 2800 + 600 sales, 2500 + 450 cost.
        $this->assertEquals(3400.0, $report['sales_net']);
        $this->assertEquals(2950.0, $report['cogs']);
        $this->assertEquals(450.0, $report['gross_profit']);
        $this->assertEquals(13.2, $report['gross_margin_pct']);
        $this->assertEquals(450.0, $report['net_profit']);
        $this->assertSame(1, $report['sales_count']);

        // The statement has to subtract on screen exactly as it does here.
        $this->assertEquals(
            $report['gross_profit'],
            round($report['sales_net'] - $report['cogs'], 2)
        );
    }

    public function test_a_ticket_discount_comes_out_of_the_margin(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['price' => 120, 'cost' => 90]);

        $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'discount_amount' => 50,
        ]);

        $report = $this->profitReport($user);

        // 600 rung up, 50 given away, 450 of goods.
        $this->assertEquals(550.0, $report['sales_net']);
        $this->assertEquals(450.0, $report['cogs']);
        $this->assertEquals(100.0, $report['gross_profit']);
    }

    public function test_a_line_with_no_cost_is_left_out_of_profit_and_reported_on_its_own(): void
    {
        $user = $this->seller();
        $costed = $this->product($user, ['price' => 120, 'cost' => 90]);
        $unknown = $this->product($user, ['name' => 'Loose sweets', 'price' => 200, 'cost' => null, 'sku' => 'LS-1']);

        $this->sell($user, [
            'items' => [
                ['product_id' => $costed->id, 'quantity' => 1],
                ['product_id' => $unknown->id, 'quantity' => 2],
            ],
        ]);

        $report = $this->profitReport($user);

        // Sales are the whole 520 the shop took, but only the 120 line has a
        // margin. Reporting 430 here would be inventing 400 of profit.
        $this->assertEquals(520.0, $report['sales_net']);
        $this->assertEquals(90.0, $report['cogs']);
        $this->assertEquals(30.0, $report['gross_profit']);
        $this->assertEquals(400.0, $report['unknown_cost_revenue']);
        $this->assertSame(1, $report['unknown_cost_lines']);

        $sweets = collect($report['by_product'])->firstWhere('name', 'Loose sweets');
        $this->assertEquals(0.0, $sweets['profit']);
        $this->assertTrue($sweets['has_unknown_cost']);
    }

    public function test_refunds_come_off_both_sales_and_cost_of_goods_sold(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['price' => 120, 'cost' => 90]);

        $sale = $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 3]]]);
        $itemId = Sale::findOrFail($sale['id'])->items->first()->id;

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/refund", [
                'items' => [['sale_item_id' => $itemId, 'quantity' => 1]],
            ])
            ->assertOk();

        $report = $this->profitReport($user);

        // Two units left standing: 240 taken, 180 of goods, 60 earned.
        $this->assertEquals(240.0, $report['sales_net']);
        $this->assertEquals(180.0, $report['cogs']);
        $this->assertEquals(60.0, $report['gross_profit']);
    }

    /* --------------------------------------------------------------- expenses */

    public function test_expenses_reduce_net_profit_but_the_till_float_is_not_an_expense(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['price' => 120, 'cost' => 90]);

        $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 10]]]);
        $this->expense($user, 'rent', 200);

        // The float and the closing count are the same rupees leaving the
        // owner's hand and coming back; booking them would eat the day's
        // profit twice over.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 5000])
            ->assertSuccessful();
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/close', ['closing_amount' => 6200])
            ->assertSuccessful();

        $report = $this->profitReport($user);

        $this->assertEquals(300.0, $report['gross_profit']);
        $this->assertEquals(200.0, $report['expenses']);
        $this->assertEquals(100.0, $report['net_profit']);
    }

    /* ---------------------------------------------------------------- wastage */

    public function test_stock_written_off_the_shelf_lands_on_the_wastage_line(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['price' => 120, 'cost' => 90, 'stock_quantity' => 100]);

        $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 10]]]);

        $writeOff = fn (float $delta, string $reason) => $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => $delta,
                'type' => 'adjustment',
                'reason' => $reason,
            ])
            ->assertSuccessful();

        $writeOff(-4, 'spoiled');
        $writeOff(-1, 'expired');
        // A recount is the shop correcting its own book, not money gone. The
        // goods were never on the shelf, so charging for them would take the
        // same rupees out twice.
        $writeOff(-2, 'count_correction');

        $report = $this->profitReport($user);

        $this->assertEquals(450.0, $report['wastage']);
        $this->assertSame(0, $report['wastage_uncosted']);
        $this->assertEquals(300.0, $report['gross_profit']);
        $this->assertEquals(-150.0, $report['net_profit']);
    }

    public function test_a_hand_written_wastage_entry_is_counted_once_not_as_an_expense_too(): void
    {
        $user = $this->seller();
        $this->expense($user, 'wastage', 500);
        $this->expense($user, 'rent', 200);

        $report = $this->profitReport($user);

        $this->assertEquals(500.0, $report['wastage']);
        $this->assertEquals(200.0, $report['expenses']);
        $this->assertEquals(-700.0, $report['net_profit']);
    }

    public function test_a_write_off_with_no_cost_price_is_flagged_rather_than_valued_at_zero(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['name' => 'Loose sweets', 'cost' => null, 'stock_quantity' => 20]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/products/{$product->id}/stock", [
                'quantity_delta' => -5,
                'type' => 'adjustment',
                'reason' => 'damaged',
            ])
            ->assertSuccessful();

        $report = $this->profitReport($user);

        $this->assertEquals(0.0, $report['wastage']);
        $this->assertSame(1, $report['wastage_uncosted']);
    }

    /* ------------------------------------------------------------ breakdowns */

    public function test_the_breakdown_ranks_products_and_rolls_up_by_category(): void
    {
        $user = $this->seller();
        $drinks = ProductCategory::create(['user_id' => $user->id, 'name' => 'Drinks', 'slug' => 'drinks']);

        $cola = $this->product($user, ['product_category_id' => $drinks->id]);
        $chips = $this->product($user, ['name' => 'Chips', 'sku' => 'CH-1', 'price' => 50, 'cost' => 40]);

        $this->sell($user, [
            'items' => [
                ['product_id' => $cola->id, 'quantity' => 10],
                ['product_id' => $chips->id, 'quantity' => 2],
            ],
        ]);

        $report = $this->profitReport($user);

        $this->assertSame('Cola 500ml', $report['by_product'][0]['name']);
        $this->assertEquals(1200.0, $report['by_product'][0]['revenue']);
        $this->assertEquals(300.0, $report['by_product'][0]['profit']);
        $this->assertEquals(25.0, $report['by_product'][0]['margin_pct']);

        $byCategory = collect($report['by_category']);
        $this->assertEquals(300.0, $byCategory->firstWhere('category', 'Drinks')['profit']);
        // Chips belong to no category, and takings with nowhere to go must
        // still be visible rather than dropped by the join.
        $this->assertEquals(20.0, $byCategory->firstWhere('category', 'Uncategorised')['profit']);
    }

    /* --------------------------------------------------------------- periods */

    public function test_the_report_only_counts_sales_inside_the_period(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['price' => 120, 'cost' => 90, 'stock_quantity' => 500]);

        $this->travelTo(Carbon::create(2026, 5, 20, 10, 0));
        $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 2]]]);

        $old = Sale::findOrFail($this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
        ])['id']);
        $old->forceFill(['sold_at' => Carbon::create(2026, 4, 10, 9, 0)])->save();

        $this->assertEquals(240.0, $this->profitReport($user, 'from=2026-05-01&to=2026-05-31')['sales_net']);
        $this->assertEquals(480.0, $this->profitReport($user, 'year=2026&month=4')['sales_net']);
        $this->assertEquals(720.0, $this->profitReport($user, 'year=2026')['sales_net']);
        // Monday 18 May to Sunday 24 May.
        $this->assertEquals(240.0, $this->profitReport($user, 'start=2026-05-20')['sales_net']);
        // Nothing asked for is this month.
        $this->assertEquals(240.0, $this->profitReport($user)['sales_net']);
    }

    public function test_a_sale_rung_up_late_on_the_closing_date_is_still_inside_the_period(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['price' => 120, 'cost' => 90]);

        $this->travelTo(Carbon::create(2026, 5, 31, 22, 30));
        $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 1]]]);

        $this->assertEquals(120.0, $this->profitReport($user, 'from=2026-05-01&to=2026-05-31')['sales_net']);
    }

    public function test_a_backwards_period_is_rejected(): void
    {
        $user = $this->seller();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/profit?from=2026-05-31&to=2026-05-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    /* ---------------------------------------------------------- cash position */

    public function test_cash_position_separates_the_drawer_from_udhaar_and_stock(): void
    {
        $user = $this->seller();
        $product = $this->product($user, ['price' => 120, 'cost' => 90, 'stock_quantity' => 100]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 1000])
            ->assertSuccessful();

        $this->sell($user, ['items' => [['product_id' => $product->id, 'quantity' => 2]]]);
        $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
            'paid_amount' => 100,
            'deposit_method' => 'cash',
        ]);

        $report = $this->cashReport($user);

        // Drawer: 1000 float + 240 cash sale + 100 deposit. The other 500 of
        // that credit sale is still on the customer's slate.
        $this->assertEquals(1340.0, $report['cash_in_drawer']);
        $this->assertEquals(500.0, $report['receivable']);
        $this->assertSame(1, $report['receivable_count']);

        // 93 units left at 90 each.
        $this->assertEquals(8370.0, $report['stock_at_cost']);

        $this->assertEquals(240.0, $report['by_payment_method']['cash']);
        $this->assertEquals(600.0, $report['by_payment_method']['credit']);
        $this->assertEquals(0.0, $report['by_payment_method']['card']);
        $this->assertEquals(840.0, $report['takings_total']);
    }

    public function test_a_settled_debt_stops_being_receivable(): void
    {
        $user = $this->seller();
        $product = $this->product($user);

        $sale = $this->sell($user, [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'credit',
            'customer_name' => 'Bilal Traders',
        ]);

        $this->assertEquals(240.0, $this->cashReport($user)['receivable']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/sales/{$sale['id']}/payments", ['amount' => 240, 'method' => 'cash'])
            ->assertOk();

        $this->assertEquals(0.0, $this->cashReport($user)['receivable']);
        $this->assertSame(0, $this->cashReport($user)['receivable_count']);
    }

    public function test_stock_with_no_cost_price_is_counted_but_not_valued(): void
    {
        $user = $this->seller();
        $this->product($user, ['cost' => 90, 'stock_quantity' => 10]);
        $this->product($user, ['name' => 'Loose sweets', 'sku' => 'LS-1', 'cost' => null, 'stock_quantity' => 4]);

        $report = $this->cashReport($user);

        $this->assertEquals(900.0, $report['stock_at_cost']);
        $this->assertSame(1, $report['stock_uncosted_products']);
    }

    /* ------------------------------------------------------------ categories */

    public function test_a_category_the_shop_already_used_is_retired_not_deleted(): void
    {
        $user = $this->seller();

        // An install seeded back when the list was a personal-finance one.
        $legacy = ExpenseCategory::create([
            'name' => 'Petrol',
            'slug' => 'petrol',
            'kind' => 'expense',
            'is_system' => true,
        ]);
        $entry = CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $legacy->id,
            'type' => 'expense',
            'amount' => 3000,
            'entry_date' => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/day-book/open', ['opening_amount' => 500])
            ->assertSuccessful();

        $this->seed(ExpenseCategorySeeder::class);

        // Deleting it would take the only record of what that 3000 was spent on
        // with it — the entry still has to be able to name its category.
        $this->assertDatabaseHas('expense_categories', ['id' => $legacy->id, 'is_active' => false]);
        $this->assertSame('Petrol', $entry->fresh()->category->name);

        $this->assertDatabaseHas('expense_categories', ['slug' => 'stock-purchase', 'is_active' => true]);
        // A rename keeps the row, so entries already filed under "Utilities"
        // follow the new name instead of being orphaned beside it.
        $this->assertDatabaseHas('expense_categories', [
            'slug' => 'utilities',
            'name' => 'Bijli/Gas (Utilities)',
            'is_active' => true,
        ]);
        // The day book's own categories belong to no seeded list and must
        // survive a re-seed, or the till stops being able to post a float.
        $this->assertDatabaseHas('expense_categories', ['slug' => 'till-float', 'is_active' => true]);
    }

    /* ---------------------------------------------------------------- access */

    public function test_another_shops_takings_never_reach_this_report(): void
    {
        $mine = $this->seller();
        $theirs = $this->seller();

        $this->sell($theirs, [
            'items' => [['product_id' => $this->product($theirs)->id, 'quantity' => 4]],
            'payment_method' => 'credit',
            'customer_name' => 'Their customer',
        ]);
        $this->expense($theirs, 'rent', 900);

        $report = $this->profitReport($mine);
        $this->assertEquals(0.0, $report['sales_net']);
        $this->assertEquals(0.0, $report['gross_profit']);
        $this->assertEquals(0.0, $report['expenses']);
        $this->assertSame([], $report['by_product']);

        $cash = $this->cashReport($mine);
        $this->assertEquals(0.0, $cash['receivable']);
        $this->assertEquals(0.0, $cash['stock_at_cost']);
    }

    public function test_staff_see_their_own_shops_profit(): void
    {
        $owner = $this->seller();
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Corner Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $staff = User::factory()->create();
        $staff->assignRole(User::ROLE_STAFF, $shop->id);

        $this->sell($owner, [
            'items' => [['product_id' => $this->product($owner)->id, 'quantity' => 2]],
        ]);

        // The takings belong to the shop, so the cashier reads the same figure
        // the owner does rather than an empty report of their own.
        $this->assertEquals(240.0, $this->profitReport($staff)['sales_net']);
    }

    public function test_guest_cannot_reach_the_new_reports(): void
    {
        $this->getJson('/api/reports/profit')->assertUnauthorized();
        $this->getJson('/api/reports/cash-position')->assertUnauthorized();
    }
}

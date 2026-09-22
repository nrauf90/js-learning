<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Services\Pos\DayBookService;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpenseCategorySeeder::class);
    }

    public function test_weekly_report_aggregates_iso_week(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $stock = ExpenseCategory::where('slug', 'stock-purchase')->firstOrFail();
        $shopSales = ExpenseCategory::where('slug', 'sales')->firstOrFail();

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $stock->id,
            'type' => 'expense',
            'amount' => 1000,
            'entry_date' => '2026-07-28',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $stock->id,
            'type' => 'expense',
            'amount' => 500,
            'entry_date' => '2026-07-30',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $shopSales->id,
            'type' => 'income',
            'amount' => 50000,
            'entry_date' => '2026-07-29',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $stock->id,
            'type' => 'expense',
            'amount' => 999,
            'entry_date' => '2026-08-04',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/weekly?start=2026-07-30')
            ->assertOk()
            ->assertJsonPath('period.start', '2026-07-27')
            ->assertJsonPath('period.end', '2026-08-02')
            ->assertJsonPath('total_income', 50000)
            ->assertJsonPath('total_expense', 1500)
            ->assertJsonPath('net', 48500)
            ->assertJsonFragment(['category' => 'Stock Purchase', 'amount' => 1500])
            ->assertJsonFragment(['category' => 'Shop Sales', 'amount' => 50000]);
    }

    public function test_monthly_report_aggregates_calendar_month(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $rent = ExpenseCategory::where('slug', 'rent')->firstOrFail();
        $otherIncome = ExpenseCategory::where('slug', 'other-income')->firstOrFail();

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $rent->id,
            'type' => 'expense',
            'amount' => 3000,
            'entry_date' => '2026-07-01',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $rent->id,
            'type' => 'expense',
            'amount' => 2000,
            'entry_date' => '2026-07-31',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $otherIncome->id,
            'type' => 'income',
            'amount' => 25000,
            'entry_date' => '2026-07-15',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $rent->id,
            'type' => 'expense',
            'amount' => 100,
            'entry_date' => '2026-08-01',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/monthly?year=2026&month=7')
            ->assertOk()
            ->assertJsonPath('period.start', '2026-07-01')
            ->assertJsonPath('period.end', '2026-07-31')
            ->assertJsonPath('total_expense', 5000)
            ->assertJsonPath('total_income', 25000)
            ->assertJsonPath('net', 20000)
            ->assertJsonCount(1, 'expense_by_category')
            ->assertJsonCount(1, 'income_by_category');
    }

    public function test_yearly_report_aggregates_calendar_year(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $stock = ExpenseCategory::where('slug', 'stock-purchase')->firstOrFail();
        $shopSales = ExpenseCategory::where('slug', 'sales')->firstOrFail();

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $shopSales->id,
            'type' => 'income',
            'amount' => 120000,
            'entry_date' => '2026-01-15',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $stock->id,
            'type' => 'expense',
            'amount' => 4000,
            'entry_date' => '2026-12-20',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $shopSales->id,
            'type' => 'income',
            'amount' => 50000,
            'entry_date' => '2025-12-31',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/yearly?year=2026')
            ->assertOk()
            ->assertJsonPath('period.start', '2026-01-01')
            ->assertJsonPath('period.end', '2026-12-31')
            ->assertJsonPath('total_income', 120000)
            ->assertJsonPath('total_expense', 4000)
            ->assertJsonPath('net', 116000);
    }

    public function test_weekly_report_includes_sql_aggregated_daily_breakdown(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $stock = ExpenseCategory::where('slug', 'stock-purchase')->firstOrFail();
        $shopSales = ExpenseCategory::where('slug', 'sales')->firstOrFail();

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $stock->id,
            'type' => 'expense',
            'amount' => 200,
            'entry_date' => '2026-07-28',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $stock->id,
            'type' => 'expense',
            'amount' => 300,
            'entry_date' => '2026-07-28',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $shopSales->id,
            'type' => 'income',
            'amount' => 1000,
            'entry_date' => '2026-07-29',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/weekly?start=2026-07-30')
            ->assertOk();

        $byDay = $response->json('by_day');
        $this->assertIsArray($byDay);

        $day28 = collect($byDay)->firstWhere('date', '2026-07-28');
        $this->assertNotNull($day28);
        $this->assertEquals(500, $day28['expense']);
        $this->assertEquals(0, $day28['income']);

        $day29 = collect($byDay)->firstWhere('date', '2026-07-29');
        $this->assertNotNull($day29);
        $this->assertEquals(1000, $day29['income']);
        $this->assertEquals(0, $day29['expense']);
    }

    public function test_reports_exclude_other_users_entries(): void
    {
        $owner = User::factory()->create();
        $this->subscribeUser($owner);
        $other = User::factory()->create();
        $this->subscribeUser($other);
        $category = ExpenseCategory::where('kind', 'expense')->firstOrFail();

        CashEntry::create([
            'user_id' => $owner->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 5000,
            'entry_date' => '2026-07-15',
        ]);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/reports/monthly?year=2026&month=7')
            ->assertOk()
            ->assertJsonPath('total_expense', 0);
    }

    public function test_guest_cannot_access_reports(): void
    {
        $this->getJson('/api/reports/weekly?start=2026-07-30')->assertUnauthorized();
        $this->getJson('/api/reports/monthly?year=2026&month=7')->assertUnauthorized();
        $this->getJson('/api/reports/yearly?year=2026')->assertUnauthorized();
    }

    public function test_weekly_report_requires_start_date(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/weekly')
            ->assertUnprocessable();
    }

    /**
     * Sales are written straight to the table rather than rung up through the
     * till: what this suite cares about is how a row aggregates, not how it
     * got there.
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

    /**
     * Sales stopped posting into cash_entries when the day book took over the
     * drawer, so the report reads the sales book itself — net of refunds, and
     * across every payment method — under an income row of its own.
     */
    public function test_weekly_report_counts_net_till_sales_as_income(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $otherIncome = ExpenseCategory::where('slug', 'other-income')->firstOrFail();

        $this->sale($user, ['total' => 2000, 'paid_amount' => 2000, 'sold_at' => '2026-07-28 10:00:00']);
        // A refunded sale contributes nothing.
        $this->sale($user, [
            'total' => 800,
            'paid_amount' => 800,
            'refunded_amount' => 800,
            'refunded_at' => '2026-07-29 12:30:00',
            'status' => 'refunded',
            'sold_at' => '2026-07-29 12:00:00',
        ]);
        // Takings are income however they settled, not just the cash ones.
        $this->sale($user, [
            'total' => 500,
            'paid_amount' => 500,
            'payment_method' => 'card',
            'sold_at' => '2026-07-29 18:30:00',
        ]);
        // Outside the week — must not leak in.
        $this->sale($user, ['total' => 9999, 'paid_amount' => 9999, 'sold_at' => '2026-08-10 10:00:00']);

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $otherIncome->id,
            'type' => 'income',
            'amount' => 300,
            'entry_date' => '2026-07-28',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/weekly?start=2026-07-30')
            ->assertOk()
            ->assertJsonPath('total_income', 2800)
            ->assertJsonPath('net', 2800)
            ->assertJsonFragment(['category' => 'Till Sales', 'amount' => 2500])
            ->assertJsonFragment(['category' => 'Other Income', 'amount' => 300]);

        $byDay = collect($response->json('by_day'));
        $this->assertEquals(2300, $byDay->firstWhere('date', '2026-07-28')['income']);
        $this->assertEquals(500, $byDay->firstWhere('date', '2026-07-29')['income']);
        $this->assertNull($byDay->firstWhere('date', '2026-08-10'));
    }

    /**
     * The float out in the morning and the drawer count back at night are the
     * same rupees moving, not spending and earning. Both are excluded from
     * totals, category lists and the daily breakdown — otherwise every day's
     * "income" would be the closing count and every day's "expense" the float.
     */
    public function test_reports_exclude_the_day_books_float_and_close_entries(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $stock = ExpenseCategory::where('slug', 'stock-purchase')->firstOrFail();
        $otherIncome = ExpenseCategory::where('slug', 'other-income')->firstOrFail();

        $float = ExpenseCategory::create([
            'slug' => DayBookService::FLOAT_CATEGORY_SLUG,
            'name' => 'Till Float',
            'kind' => 'expense',
            'is_system' => true,
        ]);
        $close = ExpenseCategory::create([
            'slug' => DayBookService::CLOSE_CATEGORY_SLUG,
            'name' => 'Till Close',
            'kind' => 'income',
            'is_system' => true,
        ]);

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $float->id,
            'type' => 'expense',
            'amount' => 5000,
            'entry_date' => '2026-07-28',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $close->id,
            'type' => 'income',
            'amount' => 9000,
            'entry_date' => '2026-07-28',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $stock->id,
            'type' => 'expense',
            'amount' => 700,
            'entry_date' => '2026-07-28',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $otherIncome->id,
            'type' => 'income',
            'amount' => 300,
            'entry_date' => '2026-07-28',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/weekly?start=2026-07-30')
            ->assertOk()
            ->assertJsonPath('total_income', 300)
            ->assertJsonPath('total_expense', 700)
            ->assertJsonPath('net', -400)
            ->assertJsonMissing(['category' => 'Till Float', 'amount' => 5000])
            ->assertJsonMissing(['category' => 'Till Close', 'amount' => 9000])
            ->assertJsonCount(1, 'income_by_category')
            ->assertJsonCount(1, 'expense_by_category');

        $day = collect($response->json('by_day'))->firstWhere('date', '2026-07-28');
        $this->assertNotNull($day);
        $this->assertEquals(300, $day['income']);
        $this->assertEquals(700, $day['expense']);
    }

    /**
     * Sales rows carry the shop owner's id no matter who rang them up, so a
     * cashier's report reads the same takings the owner sees.
     */
    public function test_a_staff_report_reads_the_shop_owners_takings(): void
    {
        $owner = User::factory()->create();
        $this->subscribeUser($owner);
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Corner Store']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);

        $staff = User::factory()->create();
        $staff->assignRole(User::ROLE_STAFF, $shop->id);

        $this->sale($owner, ['total' => 1500, 'paid_amount' => 1500, 'sold_at' => '2026-07-28 10:00:00']);

        $this->actingAs($staff, 'sanctum')
            ->getJson('/api/reports/weekly?start=2026-07-30')
            ->assertOk()
            ->assertJsonPath('total_income', 1500)
            ->assertJsonFragment(['category' => 'Till Sales', 'amount' => 1500]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use App\Models\User;
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
        $grocery = ExpenseCategory::where('slug', 'grocery')->firstOrFail();
        $salary = ExpenseCategory::where('slug', 'salary')->firstOrFail();

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $grocery->id,
            'type' => 'expense',
            'amount' => 1000,
            'entry_date' => '2026-07-28',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $grocery->id,
            'type' => 'expense',
            'amount' => 500,
            'entry_date' => '2026-07-30',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'type' => 'income',
            'amount' => 50000,
            'entry_date' => '2026-07-29',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $grocery->id,
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
            ->assertJsonFragment(['category' => 'Grocery', 'amount' => 1500])
            ->assertJsonFragment(['category' => 'Salary', 'amount' => 50000]);
    }

    public function test_monthly_report_aggregates_calendar_month(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $petrol = ExpenseCategory::where('slug', 'petrol')->firstOrFail();
        $freelance = ExpenseCategory::where('slug', 'freelance')->firstOrFail();

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $petrol->id,
            'type' => 'expense',
            'amount' => 3000,
            'entry_date' => '2026-07-01',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $petrol->id,
            'type' => 'expense',
            'amount' => 2000,
            'entry_date' => '2026-07-31',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $freelance->id,
            'type' => 'income',
            'amount' => 25000,
            'entry_date' => '2026-07-15',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $petrol->id,
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
        $grocery = ExpenseCategory::where('slug', 'grocery')->firstOrFail();
        $salary = ExpenseCategory::where('slug', 'salary')->firstOrFail();

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
            'type' => 'income',
            'amount' => 120000,
            'entry_date' => '2026-01-15',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $grocery->id,
            'type' => 'expense',
            'amount' => 4000,
            'entry_date' => '2026-12-20',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
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
        $grocery = ExpenseCategory::where('slug', 'grocery')->firstOrFail();
        $salary = ExpenseCategory::where('slug', 'salary')->firstOrFail();

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $grocery->id,
            'type' => 'expense',
            'amount' => 200,
            'entry_date' => '2026-07-28',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $grocery->id,
            'type' => 'expense',
            'amount' => 300,
            'entry_date' => '2026-07-28',
        ]);
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $salary->id,
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
}

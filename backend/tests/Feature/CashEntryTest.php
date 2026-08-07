<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class CashEntryTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpenseCategorySeeder::class);
    }

    public function test_lists_seeded_categories(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(13, 'categories');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/categories?kind=expense')
            ->assertOk()
            ->assertJsonCount(11, 'categories')
            ->assertJsonFragment(['slug' => 'stock-purchase']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/categories?kind=income')
            ->assertOk()
            ->assertJsonCount(2, 'categories')
            ->assertJsonFragment(['slug' => 'sales']);
    }

    /**
     * A shop upgrading from the personal-finance categories keeps its retired
     * rows — the entries already filed against "Car Wash" still need a name to
     * report under. It must not still be offered the choice.
     *
     * The counts above cannot catch this: a freshly seeded test database has no
     * legacy rows to begin with, so only a database that actually carried them
     * shows the fault. This test manufactures one.
     */
    public function test_a_retired_category_is_no_longer_offered_in_the_picker(): void
    {
        $user = User::factory()->create();

        $retired = ExpenseCategory::query()->create([
            'name' => 'Car Wash',
            'slug' => 'car-wash',
            'kind' => 'expense',
            'is_system' => true,
            'is_active' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/categories?kind=expense')
            ->assertOk()
            ->assertJsonCount(11, 'categories')
            ->assertJsonMissing(['slug' => 'car-wash']);

        // Still on the books, just not on the menu.
        $this->assertDatabaseHas('expense_categories', ['id' => $retired->id]);
        $this->assertNotContains('Car Wash', array_column($response->json('categories'), 'name'));
    }

    public function test_user_can_create_income_entry(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $salary = ExpenseCategory::where('slug', 'sales')->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cash-entries', [
                'category_id' => $salary->id,
                'type' => 'income',
                'amount' => 85000,
                'entry_date' => '2026-07-30',
                'note' => 'July salary',
            ])
            ->assertCreated()
            ->assertJsonPath('entry.type', 'income')
            ->assertJsonPath('entry.category.slug', 'sales');
    }

    public function test_entry_amount_has_a_max_bound(): void
    {
        // M12-T5: unbounded amount could be abused (huge numbers, overflow-y
        // formatting). Amount must reject values above the sane max.
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $salary = ExpenseCategory::where('slug', 'sales')->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cash-entries', [
                'category_id' => $salary->id,
                'type' => 'income',
                'amount' => 9999999999,
                'entry_date' => '2026-07-30',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_income_entry_rejects_expense_category(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $grocery = ExpenseCategory::where('slug', 'stock-purchase')->firstOrFail();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/cash-entries', [
                'category_id' => $grocery->id,
                'type' => 'income',
                'amount' => 1000,
                'entry_date' => '2026-07-30',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_user_can_create_and_list_entries_for_a_date(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $category = ExpenseCategory::where('slug', 'stock-purchase')->firstOrFail();

        $create = $this->actingAs($user, 'sanctum')
            ->postJson('/api/cash-entries', [
                'category_id' => $category->id,
                'type' => 'expense',
                'amount' => 1500.5,
                'entry_date' => '2026-07-30',
                'note' => 'Weekly grocery',
            ]);

        $create->assertCreated()
            ->assertJsonPath('entry.type', 'expense')
            ->assertJsonPath('entry.category.slug', 'stock-purchase');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries?date=2026-07-30')
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('entries.0.note', 'Weekly grocery');
    }

    public function test_user_can_update_own_entry(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $category = ExpenseCategory::where('slug', 'rent')->firstOrFail();
        $entry = CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100,
            'entry_date' => '2026-07-30',
            'note' => 'Old',
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/cash-entries/'.$entry->id, [
                'amount' => 250,
                'note' => 'Updated',
            ])
            ->assertOk()
            ->assertJsonPath('entry.amount', 250)
            ->assertJsonPath('entry.note', 'Updated');
    }

    public function test_user_can_delete_own_entry(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $category = ExpenseCategory::firstOrFail();
        $entry = CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'income',
            'amount' => 5000,
            'entry_date' => '2026-07-30',
        ]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/cash-entries/'.$entry->id)
            ->assertOk();

        $this->assertDatabaseMissing('cash_entries', ['id' => $entry->id]);
    }

    public function test_user_cannot_update_another_users_entry(): void
    {
        $owner = User::factory()->create();
        $this->subscribeUser($owner);
        $intruder = User::factory()->create();
        $this->subscribeUser($intruder);
        $category = ExpenseCategory::firstOrFail();
        $entry = CashEntry::create([
            'user_id' => $owner->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100,
            'entry_date' => '2026-07-30',
        ]);

        $this->actingAs($intruder, 'sanctum')
            ->putJson('/api/cash-entries/'.$entry->id, ['amount' => 999])
            ->assertForbidden();
    }

    public function test_list_does_not_include_other_users_entries(): void
    {
        $a = User::factory()->create();
        $this->subscribeUser($a);
        $b = User::factory()->create();
        $this->subscribeUser($b);
        $category = ExpenseCategory::firstOrFail();

        CashEntry::create([
            'user_id' => $a->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100,
            'entry_date' => '2026-07-30',
        ]);

        $this->actingAs($b, 'sanctum')
            ->getJson('/api/cash-entries?date=2026-07-30')
            ->assertOk()
            ->assertJsonCount(0, 'entries');
    }

    public function test_unfiltered_list_is_paginated(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $category = ExpenseCategory::where('kind', 'expense')->firstOrFail();

        foreach (range(1, 5) as $i) {
            CashEntry::create([
                'user_id' => $user->id,
                'category_id' => $category->id,
                'type' => 'expense',
                'amount' => 100 + $i,
                'entry_date' => "2026-07-0{$i}",
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'entries')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.current_page', 1);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries?per_page=500')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries?date=2026-07-01')
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * The day book screen shows one page of entries but three figures for the
     * whole day. Adding up only the page on screen would have the totals shrink
     * as the shopkeeper paged forward.
     */
    public function test_a_day_is_paginated_but_its_totals_cover_the_whole_day(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $expense = ExpenseCategory::where('kind', 'expense')->firstOrFail();
        $income = ExpenseCategory::where('kind', 'income')->firstOrFail();

        foreach (range(1, 4) as $i) {
            CashEntry::create([
                'user_id' => $user->id,
                'category_id' => $expense->id,
                'type' => 'expense',
                'amount' => 100,
                'entry_date' => '2026-07-30',
            ]);
        }

        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $income->id,
            'type' => 'income',
            'amount' => 1000,
            'entry_date' => '2026-07-30',
        ]);

        // A different day's money never lands on this day's totals.
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $expense->id,
            'type' => 'expense',
            'amount' => 5000,
            'entry_date' => '2026-07-29',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries?date=2026-07-30&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'entries')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3)
            ->assertJsonPath('totals.income', 1000)
            ->assertJsonPath('totals.expense', 400)
            ->assertJsonPath('totals.net', 600);

        // The last page carries the same totals as the first.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries?date=2026-07-30&per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('totals.net', 600);
    }

    public function test_guest_cannot_access_cash_entries(): void
    {
        $this->getJson('/api/cash-entries')->assertUnauthorized();
        $this->getJson('/api/categories')->assertUnauthorized();
    }

    public function test_unsubscribed_user_cannot_access_cash_entries(): void
    {
        $user = User::factory()->create();
        $this->expireTrial($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries')
            ->assertStatus(402)
            ->assertJsonPath('code', 'trial_expired');
    }
}

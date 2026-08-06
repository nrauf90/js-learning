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
            ->assertJsonCount(15, 'categories');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/categories?kind=expense')
            ->assertOk()
            ->assertJsonCount(10, 'categories')
            ->assertJsonFragment(['slug' => 'petrol']);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/categories?kind=income')
            ->assertOk()
            ->assertJsonCount(5, 'categories')
            ->assertJsonFragment(['slug' => 'salary']);
    }

    public function test_user_can_create_income_entry(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $salary = ExpenseCategory::where('slug', 'salary')->firstOrFail();

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
            ->assertJsonPath('entry.category.slug', 'salary');
    }

    public function test_entry_amount_has_a_max_bound(): void
    {
        // M12-T5: unbounded amount could be abused (huge numbers, overflow-y
        // formatting). Amount must reject values above the sane max.
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $salary = ExpenseCategory::where('slug', 'salary')->firstOrFail();

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
        $grocery = ExpenseCategory::where('slug', 'grocery')->firstOrFail();

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
        $category = ExpenseCategory::where('slug', 'grocery')->firstOrFail();

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
            ->assertJsonPath('entry.category.slug', 'grocery');

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
        $category = ExpenseCategory::where('slug', 'petrol')->firstOrFail();
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

        // Date-filtered requests stay a plain (non-paginated) list.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries?date=2026-07-01')
            ->assertOk()
            ->assertJsonCount(1, 'entries')
            ->assertJsonMissingPath('meta');
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

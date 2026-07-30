<?php

namespace Tests\Feature;

use App\Models\CashEntry;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpenseCategorySeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_non_admin_is_forbidden_from_admin_routes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertForbidden();
    }

    public function test_guest_is_unauthorized_on_admin_routes(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public function test_admin_can_view_dashboard_stats(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure(['stats' => ['total_users', 'active_subscriptions', 'monthly_revenue', 'total_entries'], 'recent_users', 'recent_payments']);
    }

    public function test_admin_can_promote_another_user_to_admin(): void
    {
        // Regression test: User::$fillable previously omitted `is_admin`,
        // so this update silently no-op'd despite returning 200 OK.
        $admin = $this->admin();
        $target = User::factory()->create(['is_admin' => false]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$target->id}", ['is_admin' => true])
            ->assertOk()
            ->assertJsonPath('user.is_admin', true);

        $this->assertTrue($target->fresh()->is_admin);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/users/{$admin->id}", ['is_admin' => false])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Cannot remove admin privileges from yourself');
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);
    }

    public function test_admin_can_delete_another_user_with_cascade(): void
    {
        $admin = $this->admin();
        $target = User::factory()->create();
        $this->subscribeUser($target);
        $category = ExpenseCategory::firstOrFail();
        CashEntry::create([
            'user_id' => $target->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100,
            'entry_date' => now()->toDateString(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('cash_entries', ['user_id' => $target->id]);
    }

    public function test_admin_can_list_cash_entries_across_users(): void
    {
        // Regression test: the admin cash-entries UI previously called a
        // non-existent /api/admin/entries endpoint (correct route is
        // /api/admin/cash-entries) and read a `kind`/`description` payload
        // shape that doesn't match the CashEntry model's `type`/`note`.
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $category = ExpenseCategory::where('slug', 'grocery')->firstOrFail();
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 250,
            'entry_date' => now()->toDateString(),
            'note' => 'Weekly shop',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/cash-entries')
            ->assertOk();

        $response->assertJsonPath('data.0.type', 'expense');
        $response->assertJsonPath('data.0.note', 'Weekly shop');
    }

    public function test_admin_can_filter_payments_by_gateway(): void
    {
        // Regression test: filtering used to query a non-existent `gateway`
        // column; the actual Payment column is `provider`.
        $admin = $this->admin();
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'provider' => 'jazzcash',
            'plan' => 'monthly',
            'amount' => 500,
            'status' => 'completed',
            'txn_ref' => 'TXN-JC-1',
        ]);
        Payment::create([
            'user_id' => $user->id,
            'provider' => 'easypaisa',
            'plan' => 'monthly',
            'amount' => 500,
            'status' => 'completed',
            'txn_ref' => 'TXN-EP-1',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/payments?gateway=jazzcash')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('jazzcash', $data[0]['provider']);
    }

    public function test_admin_can_create_update_and_delete_category(): void
    {
        $admin = $this->admin();

        $create = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/categories', ['name' => 'Gadgets', 'kind' => 'expense'])
            ->assertCreated();

        $categoryId = $create->json('category.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/admin/categories/{$categoryId}", ['name' => 'Gadgets & Tech'])
            ->assertOk()
            ->assertJsonPath('category.name', 'Gadgets & Tech');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/categories/{$categoryId}")
            ->assertOk();

        $this->assertDatabaseMissing('expense_categories', ['id' => $categoryId]);
    }

    public function test_admin_cannot_delete_category_in_use(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $this->subscribeUser($user);
        $category = ExpenseCategory::where('slug', 'grocery')->firstOrFail();
        CashEntry::create([
            'user_id' => $user->id,
            'category_id' => $category->id,
            'type' => 'expense',
            'amount' => 100,
            'entry_date' => now()->toDateString(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/admin/categories/{$category->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('expense_categories', ['id' => $category->id]);
    }
}

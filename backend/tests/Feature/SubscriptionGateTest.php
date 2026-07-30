<?php

namespace Tests\Feature;

use App\Models\ExpenseCategory;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class SubscriptionGateTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpenseCategorySeeder::class);
    }

    public function test_unsubscribed_user_gets_402_on_cash_entries(): void
    {
        $user = User::factory()->create();
        $this->expireTrial($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries')
            ->assertStatus(402)
            ->assertJson([
                'message' => 'Your free trial has ended. Subscribe to continue.',
                'code' => 'trial_expired',
            ]);
    }

    public function test_user_on_trial_can_access_cash_entries(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries')
            ->assertOk();
    }

    public function test_subscribed_user_can_access_cash_entries(): void
    {
        $user = User::factory()->create();
        $this->subscribeUser($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries')
            ->assertOk();
    }

    public function test_expired_subscription_gets_402(): void
    {
        $user = User::factory()->create();
        $this->expireTrial($user);
        Subscription::create([
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => 'active',
            'starts_at' => now()->subMonths(2),
            'ends_at' => now()->subDay(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/weekly?start=2026-07-30')
            ->assertStatus(402)
            ->assertJsonPath('code', 'trial_expired');
    }

    public function test_reports_require_active_subscription(): void
    {
        $user = User::factory()->create();
        $this->expireTrial($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/monthly?year=2026&month=7')
            ->assertStatus(402);

        $this->subscribeUser($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/reports/monthly?year=2026&month=7')
            ->assertOk();
    }

    public function test_categories_remain_available_without_subscription(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/categories')
            ->assertOk()
            ->assertJsonCount(15, 'categories');
    }

    public function test_billing_plans_still_public(): void
    {
        $this->getJson('/api/billing/plans')->assertOk();
    }
}

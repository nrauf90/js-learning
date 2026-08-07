<?php

namespace Tests\Feature;

use App\Models\Shop;
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

        // The message has to name an action the shop can take. Self-serve
        // billing is closed, so "subscribe to continue" describes a button that
        // no longer exists anywhere in the product.
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries')
            ->assertStatus(402)
            ->assertJson([
                'message' => 'Your free trial has ended. Please contact the administrator to activate your subscription.',
                'code' => 'trial_expired',
            ]);
    }

    public function test_lapsed_shop_can_still_identify_itself_for_the_operator(): void
    {
        // The lapsed notice quotes these details, and /api/shop is behind the
        // very gate that just fired — so this endpoint has to stay readable.
        $user = User::factory()->create();
        $shop = Shop::create(['owner_id' => $user->id, 'name' => 'Sabzi Mandi Store']);
        $user->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);
        $this->expireTrial($user);

        $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('trial.expired', true)
            ->assertJsonPath('account.shop.name', 'Sabzi Mandi Store')
            ->assertJsonPath('account.email', $user->email);
    }

    public function test_staff_of_a_paid_shop_are_never_told_their_trial_lapsed(): void
    {
        // Regression: /api/billing/subscription reported per-account while the
        // gate judged the shop owner, so a paid shop's cashier saw "trial
        // expired" on a dashboard the API was happily serving.
        $owner = User::factory()->create(['created_at' => now()->subYear()]);
        $shop = Shop::create(['owner_id' => $owner->id, 'name' => 'Owner Shop']);
        $owner->assignRole(User::ROLE_SHOP_ADMIN, $shop->id);
        $this->subscribeUser($owner);

        $staff = User::factory()->create(['created_at' => now()->subYear()]);
        $staff->assignRole(User::ROLE_STAFF, $shop->id);

        $this->actingAs($staff->fresh(), 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.active', true)
            ->assertJsonPath('trial.expired', false);

        $this->actingAs($staff->fresh(), 'sanctum')
            ->getJson('/api/cash-entries')
            ->assertOk();
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
            ->assertJsonCount(13, 'categories');
    }

    public function test_billing_plans_still_public(): void
    {
        $this->getJson('/api/billing/plans')->assertOk();
    }
}

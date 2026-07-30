<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class FreeTrialTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ExpenseCategorySeeder::class);
    }

    public function test_new_user_on_trial_can_access_cash_entries(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries')
            ->assertOk();
    }

    public function test_trial_status_in_subscription_endpoint(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('trial.active', true)
            ->assertJsonStructure(['trial' => ['active', 'ends_at', 'days_remaining', 'expired']]);
    }

    public function test_expired_trial_gets_402_with_trial_expired_code(): void
    {
        $user = User::factory()->create();
        $this->expireTrial($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries')
            ->assertStatus(402)
            ->assertJsonPath('code', 'trial_expired');
    }

    public function test_subscribed_user_bypasses_trial_check_even_if_old_account(): void
    {
        $user = User::factory()->create(['created_at' => now()->subYear()]);
        $this->subscribeUser($user);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/cash-entries')
            ->assertOk();
    }

    public function test_qa_expire_trial_route_backdates_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/qa/expire-trial')
            ->assertOk();

        $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/api/cash-entries')
            ->assertStatus(402)
            ->assertJsonPath('code', 'trial_expired');
    }
}

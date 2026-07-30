<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_plans_are_public(): void
    {
        $this->getJson('/api/billing/plans')
            ->assertOk()
            ->assertJsonPath('currency', 'PKR')
            ->assertJsonFragment(['id' => 'monthly', 'amount' => 500])
            ->assertJsonFragment(['id' => 'yearly', 'amount' => 5400]);
    }

    public function test_guest_cannot_checkout(): void
    {
        $this->postJson('/api/billing/checkout', [
            'plan' => 'monthly',
            'provider' => 'jazzcash',
        ])->assertUnauthorized();
    }

    public function test_user_can_initiate_checkout_in_sandbox(): void
    {
        config(['billing.sandbox' => true]);

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan' => 'monthly',
                'provider' => 'jazzcash',
            ]);

        $response->assertCreated()
            ->assertJsonPath('payment.plan', 'monthly')
            ->assertJsonPath('payment.amount', 500)
            ->assertJsonPath('checkout.sandbox', true);

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'plan' => 'monthly',
            'status' => 'pending',
        ]);
    }

    public function test_sandbox_complete_activates_subscription(): void
    {
        config(['billing.sandbox' => true]);

        $user = User::factory()->create();

        $checkout = $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan' => 'yearly',
                'provider' => 'easypaisa',
            ]);

        $paymentId = $checkout->json('payment.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/sandbox/complete/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('subscription.plan', 'yearly')
            ->assertJsonPath('subscription.active', true);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'status' => 'completed',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.plan', 'yearly')
            ->assertJsonPath('subscription.active', true);
    }

    public function test_yearly_subscription_extends_existing_active_plan(): void
    {
        config(['billing.sandbox' => true]);

        $user = User::factory()->create();

        $first = $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', ['plan' => 'monthly', 'provider' => 'jazzcash'])
            ->json('payment.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/sandbox/complete/{$first}")
            ->assertOk();

        $endsBefore = $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/subscription')
            ->json('subscription.ends_at');

        $second = $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', ['plan' => 'monthly', 'provider' => 'jazzcash'])
            ->json('payment.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/sandbox/complete/{$second}")
            ->assertOk();

        $endsAfter = $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/subscription')
            ->json('subscription.ends_at');

        $this->assertNotSame($endsBefore, $endsAfter);
    }

    public function test_receipt_addon_requires_base_subscription(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/billing/checkout', [
                'plan' => 'receipt_addon',
                'provider' => 'jazzcash',
            ])
            ->assertUnprocessable();
    }

    public function test_jazzcash_ipn_activates_pending_payment_with_valid_sandbox_payload(): void
    {
        config(['billing.sandbox' => true]);

        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id,
            'provider' => 'jazzcash',
            'plan' => 'monthly',
            'amount' => 500,
            'currency' => 'PKR',
            'status' => 'pending',
            'txn_ref' => 'CF-TESTIPN123',
        ]);

        $this->postJson('/api/billing/jazzcash/ipn', [
            'pp_TxnRefNo' => $payment->txn_ref,
            'pp_ResponseCode' => '000',
            'status' => 'completed',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'completed']);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'plan' => 'monthly', 'status' => 'active']);
    }
}

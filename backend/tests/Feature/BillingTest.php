<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSubscribedUser;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use CreatesSubscribedUser;
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'pdl_ntfset_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml turns local test mode on for the whole suite. Tests that
        // exercise the real Paddle path opt out explicitly via liveMode().
        config(['billing.sandbox' => true]);
    }

    private function liveMode(): void
    {
        config([
            'billing.sandbox' => false,
            'billing.paddle.api_key' => 'pdl_test_key',
            'billing.paddle.webhook_secret' => self::WEBHOOK_SECRET,
            'billing.paddle.environment' => 'sandbox',
            'billing.plans.monthly.price_id' => 'pri_monthly',
            'billing.plans.yearly.price_id' => 'pri_yearly',
            'billing.plans.receipt_addon.price_id' => 'pri_addon',
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function postWebhook(array $event, ?string $secret = null): TestResponse
    {
        $body = json_encode($event);
        $ts = (string) time();
        $signature = hash_hmac('sha256', $ts.':'.$body, $secret ?? self::WEBHOOK_SECRET);

        return $this->call(
            'POST',
            '/api/billing/paddle/webhook',
            [],
            [],
            [],
            [
                'HTTP_PADDLE_SIGNATURE' => "ts={$ts};h1={$signature}",
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ],
            $body,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function subscriptionEvent(
        User $user,
        array $overrides = [],
        string $eventType = 'subscription.created',
    ): array {
        return [
            'event_id' => 'evt_'.uniqid('', true),
            'event_type' => $eventType,
            'data' => array_merge([
                'id' => 'sub_test_1',
                'status' => 'active',
                'customer_id' => 'ctm_test_1',
                'started_at' => now()->toIso8601String(),
                'next_billed_at' => now()->addMonth()->toIso8601String(),
                'current_billing_period' => [
                    'starts_at' => now()->toIso8601String(),
                    'ends_at' => now()->addMonth()->toIso8601String(),
                ],
                'items' => [['price' => ['id' => 'pri_monthly'], 'quantity' => 1]],
                'custom_data' => ['user_id' => (string) $user->id, 'plan' => 'monthly'],
            ], $overrides),
        ];
    }

    public function test_plans_are_public(): void
    {
        $this->getJson('/api/billing/plans')
            ->assertOk()
            ->assertJsonPath('currency', 'USD')
            ->assertJsonPath('provider.id', 'paddle')
            ->assertJsonPath('plans.0.id', 'monthly')
            ->assertJsonPath('plans.1.id', 'yearly');
    }

    public function test_guest_cannot_checkout(): void
    {
        $this->postJson('/api/billing/checkout', ['plan' => 'monthly'])
            ->assertUnauthorized();
    }

    /**
     * The whole point of the change: a shop can no longer buy, manage or cancel
     * anything for itself, whoever is holding the till.
     *
     * @return array<string, array{string, string}>
     */
    public static function selfServeEndpoints(): array
    {
        return [
            'checkout' => ['/api/billing/checkout', 'monthly'],
            'portal' => ['/api/billing/portal', ''],
            'cancel' => ['/api/billing/cancel', ''],
        ];
    }

    #[DataProvider('selfServeEndpoints')]
    public function test_shop_account_is_refused_the_self_serve_billing_surface(string $endpoint, string $plan): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson($endpoint, $plan === '' ? [] : ['plan' => $plan])
            ->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_shop_account_cannot_complete_a_sandbox_payment(): void
    {
        // Belt and braces: the sandbox completer is the one endpoint that can
        // hand out access without a webhook, so it must be admin-only too.
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'provider' => 'manual',
            'plan' => 'monthly',
            'amount' => 5,
            'currency' => 'USD',
            'status' => 'pending',
            'txn_ref' => 'CF-SHOPBLOCK',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/billing/sandbox/complete/{$payment->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_shop_account_can_still_read_its_own_subscription_state(): void
    {
        // The app needs this to know whether it has access and what to quote on
        // the lapsed notice — closing it would blind every shop-facing screen.
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('trial.active', true)
            ->assertJsonPath('account.email', $user->email);
    }

    public function test_admin_can_initiate_checkout_in_local_test_mode(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/billing/checkout', ['plan' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('payment.plan', 'monthly')
            ->assertJsonPath('checkout.sandbox', true);

        $this->assertDatabaseHas('payments', [
            'user_id' => $admin->id,
            'plan' => 'monthly',
            'status' => 'pending',
        ]);
    }

    public function test_local_test_completion_activates_subscription(): void
    {
        $admin = User::factory()->admin()->create();

        $paymentId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/billing/checkout', ['plan' => 'yearly'])
            ->json('payment.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/billing/sandbox/complete/{$paymentId}")
            ->assertOk()
            ->assertJsonPath('subscription.plan', 'yearly')
            ->assertJsonPath('subscription.active', true);

        $this->assertDatabaseHas('payments', ['id' => $paymentId, 'status' => 'completed']);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.plan', 'yearly')
            ->assertJsonPath('subscription.active', true);
    }

    public function test_local_test_completion_is_unreachable_outside_sandbox(): void
    {
        $admin = User::factory()->admin()->create();

        $payment = Payment::create([
            'user_id' => $admin->id,
            'provider' => 'manual',
            'plan' => 'monthly',
            'amount' => 5,
            'currency' => 'USD',
            'status' => 'pending',
            'txn_ref' => 'CF-LOCALOFF1',
        ]);

        $this->liveMode();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/billing/sandbox/complete/{$payment->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'pending']);
    }

    public function test_receipt_addon_requires_base_subscription(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/billing/checkout', ['plan' => 'receipt_addon'])
            ->assertUnprocessable();
    }

    public function test_admin_can_grant_a_new_shop_its_subscription(): void
    {
        // The path that replaces self-serve checkout end to end: onboard a shop,
        // then give it access, without the shop touching billing at all.
        $admin = User::factory()->admin()->create();

        $owner = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/shop-admins', [
                'name' => 'Bilal Traders',
                'email' => 'bilal@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'shop_name' => 'Bilal Kiryana',
            ])
            ->assertCreated()
            ->json('user');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/subscriptions', [
                'user_id' => $owner['id'],
                'plan' => 'monthly',
            ])
            ->assertCreated()
            ->assertJsonPath('subscription.plan', 'monthly')
            ->assertJsonPath('subscription.status', 'active');

        $shopOwner = User::findOrFail($owner['id']);
        $this->expireTrial($shopOwner);

        $this->actingAs($shopOwner->fresh(), 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.active', true)
            // Hand-granted rows have no Paddle side, so no portal or cancel.
            ->assertJsonPath('subscription.managed', false)
            ->assertJsonPath('account.shop.name', 'Bilal Kiryana');
    }

    public function test_admin_grant_extends_rather_than_restarts_an_existing_term(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $existing = $this->subscribeUser($user, 'monthly', now()->addDays(10));

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/subscriptions', ['email' => $user->email, 'plan' => 'monthly'])
            ->assertCreated();

        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
        $this->assertTrue(
            $existing->fresh()->ends_at->greaterThan(now()->addMonth()),
            'A grant on a live subscription must add to the days already there, not replace them.'
        );
    }

    public function test_shop_account_cannot_grant_itself_a_subscription(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/subscriptions', ['user_id' => $user->id, 'plan' => 'yearly'])
            ->assertForbidden();

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_checkout_creates_a_paddle_transaction_and_returns_its_url(): void
    {
        $this->liveMode();

        Http::fake([
            '*/customers' => Http::response(['data' => ['id' => 'ctm_test_1']], 201),
            '*/transactions' => Http::response([
                'data' => [
                    'id' => 'txn_test_1',
                    'checkout' => ['url' => 'https://pay.paddle.io/hsc_test?_ptxn=txn_test_1'],
                ],
            ], 201),
        ]);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/billing/checkout', ['plan' => 'monthly'])
            ->assertCreated()
            ->assertJsonPath('checkout.sandbox', false)
            ->assertJsonPath('redirect_url', 'https://pay.paddle.io/hsc_test?_ptxn=txn_test_1');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'paddle_customer_id' => 'ctm_test_1',
        ]);
        $this->assertDatabaseHas('payments', [
            'user_id' => $admin->id,
            'provider' => 'paddle',
            'external_transaction_id' => 'txn_test_1',
            'status' => 'pending',
        ]);
    }

    public function test_checkout_never_leaks_the_paddle_api_key(): void
    {
        $this->liveMode();

        Http::fake([
            '*/customers' => Http::response(['data' => ['id' => 'ctm_test_1']], 201),
            '*/transactions' => Http::response([
                'data' => ['id' => 'txn_test_1', 'checkout' => ['url' => 'https://pay.paddle.io/x']],
            ], 201),
        ]);

        $response = $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson('/api/billing/checkout', ['plan' => 'monthly'])
            ->assertCreated();

        $this->assertStringNotContainsString('pdl_test_key', $response->getContent());
    }

    public function test_checkout_fails_cleanly_when_paddle_is_not_configured(): void
    {
        config(['billing.sandbox' => false, 'billing.paddle.api_key' => null]);

        // Regression: with no credentials this used to reach the gateway and
        // die on a TypeError (HTTP 500). It must be a clean, actionable 503.
        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson('/api/billing/checkout', ['plan' => 'monthly'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'billing_provider_unavailable');
    }

    public function test_webhook_rejects_an_unsigned_payload(): void
    {
        $this->liveMode();
        $user = User::factory()->create();

        $this->postJson('/api/billing/paddle/webhook', $this->subscriptionEvent($user))
            ->assertStatus(400)
            ->assertJsonPath('message', 'Invalid signature');

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_webhook_rejects_a_payload_signed_with_the_wrong_secret(): void
    {
        $this->liveMode();
        $user = User::factory()->create();

        $this->postWebhook($this->subscriptionEvent($user), 'pdl_ntfset_wrong')
            ->assertStatus(400);

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_webhook_fails_closed_when_no_secret_is_configured(): void
    {
        // Same reasoning as the old M12-T1 IPN regression: a public,
        // unauthenticated endpoint that can grant paid access must never
        // accept an unverifiable request, sandbox or not.
        $this->liveMode();
        config(['billing.paddle.webhook_secret' => null]);

        $user = User::factory()->create();

        $this->postWebhook($this->subscriptionEvent($user), 'anything')
            ->assertStatus(400);

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_webhook_rejects_a_stale_timestamp(): void
    {
        $this->liveMode();
        $user = User::factory()->create();

        $body = json_encode($this->subscriptionEvent($user));
        $ts = (string) (time() - 3600);
        $signature = hash_hmac('sha256', $ts.':'.$body, self::WEBHOOK_SECRET);

        $this->call('POST', '/api/billing/paddle/webhook', [], [], [], [
            'HTTP_PADDLE_SIGNATURE' => "ts={$ts};h1={$signature}",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $body)->assertStatus(400);

        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_subscription_created_webhook_activates_access(): void
    {
        $this->liveMode();
        $user = User::factory()->create();

        $this->postWebhook($this->subscriptionEvent($user))->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $user->id,
            'provider' => 'paddle',
            'external_id' => 'sub_test_1',
            'plan' => 'monthly',
            'status' => 'active',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.active', true)
            ->assertJsonPath('subscription.managed', true);
    }

    public function test_replayed_webhook_is_deduped_and_does_not_extend_the_period(): void
    {
        $this->liveMode();
        $user = User::factory()->create();
        $event = $this->subscriptionEvent($user);

        $this->postWebhook($event)->assertOk();
        $endsAt = Subscription::where('external_id', 'sub_test_1')->value('ends_at');

        $this->postWebhook($event)
            ->assertOk()
            ->assertJsonPath('message', 'Already processed');

        $this->assertSame(1, Subscription::count());
        $this->assertSame(1, WebhookEvent::count());
        $this->assertEquals($endsAt, Subscription::where('external_id', 'sub_test_1')->value('ends_at'));
    }

    public function test_subscription_updated_syncs_status_rather_than_extending(): void
    {
        $this->liveMode();
        $user = User::factory()->create();

        $this->postWebhook($this->subscriptionEvent($user))->assertOk();

        $periodEnd = now()->addMonths(2);
        $this->postWebhook($this->subscriptionEvent($user, [
            'status' => 'active',
            'current_billing_period' => [
                'starts_at' => now()->addMonth()->toIso8601String(),
                'ends_at' => $periodEnd->toIso8601String(),
            ],
            'next_billed_at' => $periodEnd->toIso8601String(),
            'scheduled_change' => ['action' => 'cancel', 'effective_at' => $periodEnd->toIso8601String()],
        ], 'subscription.updated'))->assertOk();

        $subscription = Subscription::where('external_id', 'sub_test_1')->firstOrFail();

        $this->assertSame(1, Subscription::count());
        $this->assertTrue($subscription->cancel_at_period_end);
        $this->assertSame(
            $periodEnd->startOfSecond()->toIso8601String(),
            $subscription->ends_at->startOfSecond()->toIso8601String(),
        );
    }

    public function test_canceled_subscription_loses_access(): void
    {
        $this->liveMode();
        $user = User::factory()->create();

        $this->postWebhook($this->subscriptionEvent($user))->assertOk();

        $this->postWebhook($this->subscriptionEvent($user, [
            'status' => 'canceled',
            'canceled_at' => now()->toIso8601String(),
        ], 'subscription.canceled'))->assertOk();

        $this->assertFalse(Subscription::where('external_id', 'sub_test_1')->firstOrFail()->isActive());
    }

    public function test_past_due_subscription_keeps_access_during_dunning(): void
    {
        $this->liveMode();
        $user = User::factory()->create();

        $this->postWebhook(
            $this->subscriptionEvent($user, ['status' => 'past_due'], 'subscription.past_due')
        )->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('subscription.active', true)
            ->assertJsonPath('subscription.status', 'past_due');
    }

    public function test_transaction_completed_records_the_payment(): void
    {
        $this->liveMode();
        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'provider' => 'paddle',
            'plan' => 'monthly',
            'amount' => 5,
            'currency' => 'USD',
            'status' => 'pending',
            'txn_ref' => 'CF-TXNDONE01',
        ]);

        $this->postWebhook([
            'event_id' => 'evt_txn_1',
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_test_9',
                'customer_id' => 'ctm_test_1',
                'subscription_id' => 'sub_test_1',
                'invoice_number' => 'INV-001',
                'currency_code' => 'USD',
                'details' => ['totals' => ['total' => '500']],
                'items' => [['price' => ['id' => 'pri_monthly']]],
                'custom_data' => ['user_id' => (string) $user->id, 'txn_ref' => 'CF-TXNDONE01'],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'completed',
            'external_transaction_id' => 'txn_test_9',
            'invoice_number' => 'INV-001',
        ]);
    }

    public function test_renewal_transaction_without_a_local_payment_is_recorded(): void
    {
        $this->liveMode();
        $user = User::factory()->create();
        $user->setPaddleCustomerId('ctm_renew_1');

        $this->postWebhook([
            'event_id' => 'evt_renew_1',
            'event_type' => 'transaction.completed',
            'data' => [
                'id' => 'txn_renew_1',
                'customer_id' => 'ctm_renew_1',
                'subscription_id' => 'sub_test_1',
                'currency_code' => 'USD',
                'details' => ['totals' => ['total' => '500']],
                'items' => [['price' => ['id' => 'pri_monthly']]],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'external_transaction_id' => 'txn_renew_1',
            'status' => 'completed',
            'plan' => 'monthly',
            'amount' => 5.00,
        ]);
    }

    public function test_cancel_endpoint_defers_to_paddle_and_leaves_local_state_alone(): void
    {
        $this->liveMode();
        Http::fake(['*/subscriptions/*/cancel' => Http::response(['data' => []], 200)]);

        $admin = User::factory()->admin()->create();
        $this->postWebhook($this->subscriptionEvent($admin))->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/billing/cancel')
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/subscriptions/sub_test_1/cancel')
            && $request['effective_from'] === 'next_billing_period');

        // The webhook is the only thing allowed to move subscription state.
        $this->assertSame('active', Subscription::where('external_id', 'sub_test_1')->value('status'));
    }

    public function test_portal_requires_an_existing_billing_account(): void
    {
        $this->liveMode();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson('/api/billing/portal')
            ->assertNotFound();
    }

    public function test_portal_returns_a_fresh_paddle_session_url(): void
    {
        $this->liveMode();
        Http::fake([
            '*/portal-sessions' => Http::response([
                'data' => ['urls' => ['general' => ['overview' => 'https://customer-portal.paddle.com/x']]],
            ], 201),
        ]);

        $admin = User::factory()->admin()->create();
        $admin->setPaddleCustomerId('ctm_portal_1');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/billing/portal')
            ->assertOk()
            ->assertJsonPath('url', 'https://customer-portal.paddle.com/x');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureSubscribed;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\PaddleGateway;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private PaddleGateway $paddle,
    ) {}

    public function plans(): JsonResponse
    {
        $plans = collect(config('billing.plans'))
            ->map(fn (array $plan) => [
                'id' => $plan['id'],
                'name' => $plan['name'],
                'amount' => $plan['amount'],
                'currency' => config('billing.currency', 'USD'),
                'interval' => $plan['interval'],
                'interval_count' => $plan['interval_count'],
                'description' => $plan['description'],
            ])
            ->values();

        return response()->json([
            'currency' => config('billing.currency', 'USD'),
            'provider' => [
                'id' => 'paddle',
                'label' => config('billing.paddle.label', 'Paddle'),
            ],
            'plans' => $plans,
        ]);
    }

    /**
     * The one billing endpoint a shop still reaches. It answers "do I have
     * access, and for how long" — and, when the answer is no, who to quote
     * when asking the operator to renew.
     */
    public function subscription(Request $request): JsonResponse
    {
        return response()->json(
            $this->statePayload(EnsureSubscribed::payingAccount($request->user()))
        );
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'in:monthly,yearly,receipt_addon'],
        ]);

        $user = $request->user();

        if ($validated['plan'] === 'receipt_addon' && ! $this->subscriptions->currentSubscription($user)) {
            return response()->json([
                'message' => 'Base subscription required before add-on.',
            ], 422);
        }

        $payment = $this->subscriptions->createPendingPayment(
            $user,
            $validated['plan'],
            config('billing.sandbox') ? 'manual' : $this->paddle->provider(),
        );

        // Local test mode: no provider call, no credentials needed. The
        // frontend completes the purchase against the authenticated endpoint
        // below. Gated on billing.sandbox, which must be set explicitly.
        if (config('billing.sandbox')) {
            return response()->json([
                'payment' => $this->paymentPayload($payment),
                'checkout' => ['sandbox' => true],
            ], 201);
        }

        $redirectUrl = $this->paddle->createCheckoutUrl($user, $payment);

        return response()->json([
            'payment' => $this->paymentPayload($payment->fresh()),
            'checkout' => ['sandbox' => false],
            'redirect_url' => $redirectUrl,
        ], 201);
    }

    /**
     * Short-lived, single-use link into Paddle's hosted customer portal, where
     * the customer updates their card, downloads invoices and cancels. Minted
     * per request — Paddle forbids caching these URLs.
     */
    public function portal(Request $request): JsonResponse
    {
        $url = $this->paddle->customerPortalUrl($request->user());

        if (! $url) {
            return response()->json([
                'message' => 'No billing account yet. Subscribe first to manage billing.',
            ], 404);
        }

        return response()->json(['url' => $url]);
    }

    /**
     * Cancel at period end. We do not touch local state here — Paddle emits
     * subscription.updated with the scheduled change, and the webhook is the
     * only thing allowed to move subscription state.
     */
    public function cancel(Request $request): JsonResponse
    {
        $subscription = $this->subscriptions->currentSubscription($request->user());

        if (! $subscription) {
            return response()->json(['message' => 'No active subscription.'], 404);
        }

        if (blank($subscription->external_id)) {
            return response()->json([
                'message' => 'This subscription is not managed by Paddle and must be cancelled manually.',
            ], 422);
        }

        $this->paddle->cancelSubscription($subscription->external_id);

        return response()->json([
            'message' => 'Your subscription will end at the close of the current billing period.',
        ]);
    }

    /**
     * Complete a payment locally without contacting the provider. Only exists
     * while `billing.sandbox` is on, so local dev and the Playwright harness
     * can reach subscribed state without live Paddle credentials.
     */
    public function completeTestPayment(Request $request, Payment $payment): JsonResponse
    {
        if (! config('billing.sandbox')) {
            abort(404);
        }

        if ($payment->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($payment->status !== 'pending') {
            return response()->json(
                $this->statePayload($request->user(), 'Payment already processed.')
            );
        }

        $this->subscriptions->recordPaymentCompleted($payment, [
            'provider_reference' => 'LOCAL-'.$payment->txn_ref,
            'sandbox' => true,
        ]);
        $this->subscriptions->activateLocally($payment->fresh());

        return response()->json(
            $this->statePayload($request->user(), 'Test payment completed.')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function statePayload(User $user, ?string $message = null): array
    {
        $payload = [
            'subscription' => $this->formatSubscription($this->subscriptions->currentSubscription($user)),
            'trial' => $this->subscriptions->trialStatus($user),
            'addons' => $this->subscriptions->addonStatuses($user),
            'account' => $this->accountPayload($user),
        ];

        return $message === null ? $payload : ['message' => $message] + $payload;
    }

    /**
     * What a lapsed shop quotes when it contacts the operator. Without this the
     * only way to identify the account is /api/shop, which sits behind the very
     * gate that has just locked them out.
     *
     * @return array<string, mixed>
     */
    private function accountPayload(User $user): array
    {
        $shop = $user->ownedShop()->first() ?? $user->shop()->first();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'shop' => $shop ? [
                'id' => $shop->id,
                'name' => $shop->name,
                'phone' => $shop->phone,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'txn_ref' => $payment->txn_ref,
            'plan' => $payment->plan,
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'provider' => $payment->provider,
            'status' => $payment->status,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatSubscription(?Subscription $subscription): ?array
    {
        if (! $subscription) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'plan' => $subscription->plan,
            'status' => $subscription->status,
            'starts_at' => $subscription->starts_at?->toIso8601String(),
            'ends_at' => $subscription->ends_at?->toIso8601String(),
            'renews_at' => $subscription->renews_at?->toIso8601String(),
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'managed' => filled($subscription->external_id),
            'active' => $subscription->isActive(),
        ];
    }
}

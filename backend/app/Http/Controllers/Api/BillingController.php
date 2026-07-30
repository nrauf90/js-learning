<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing\GatewayResolver;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private GatewayResolver $gateways,
    ) {}

    public function plans(): JsonResponse
    {
        $plans = collect(config('billing.plans'))
            ->map(fn (array $plan) => [
                'id' => $plan['id'],
                'name' => $plan['name'],
                'amount' => $plan['amount'],
                'currency' => config('billing.currency', 'PKR'),
                'interval' => $plan['interval'],
                'interval_count' => $plan['interval_count'],
                'description' => $plan['description'],
            ])
            ->values();

        return response()->json([
            'currency' => config('billing.currency', 'PKR'),
            'providers' => [
                ['id' => 'jazzcash', 'label' => config('billing.providers.jazzcash.label')],
                ['id' => 'easypaisa', 'label' => config('billing.providers.easypaisa.label')],
            ],
            'plans' => $plans,
        ]);
    }

    public function subscription(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $this->subscriptions->currentSubscription($user);
        $addons = $this->subscriptions->addonStatuses($user);
        $trial = $this->subscriptions->trialStatus($user);

        if (! $subscription) {
            return response()->json([
                'subscription' => null,
                'trial' => $trial,
                'addons' => $addons,
            ]);
        }

        return response()->json([
            'subscription' => [
                'id' => $subscription->id,
                'plan' => $subscription->plan,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at?->toIso8601String(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
                'active' => $subscription->isActive(),
            ],
            'trial' => $trial,
            'addons' => $addons,
        ]);
    }

    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'in:monthly,yearly,receipt_addon'],
            'provider' => ['required', 'in:jazzcash,easypaisa'],
        ]);

        if ($validated['plan'] === 'receipt_addon') {
            $base = $this->subscriptions->currentSubscription($request->user());
            if (! $base) {
                return response()->json([
                    'message' => 'Base subscription required before add-on.',
                ], 422);
            }
        }

        $payment = $this->subscriptions->createPendingPayment(
            $request->user(),
            $validated['plan'],
            $validated['provider']
        );

        $payment->load('user');
        $gateway = $this->gateways->resolve($validated['provider']);
        $checkout = $gateway->buildCheckout($payment);

        return response()->json([
            'payment' => [
                'id' => $payment->id,
                'txn_ref' => $payment->txn_ref,
                'plan' => $payment->plan,
                'amount' => (float) $payment->amount,
                'currency' => $payment->currency,
                'provider' => $payment->provider,
                'status' => $payment->status,
            ],
            'checkout' => $checkout,
        ], 201);
    }

    public function sandboxComplete(Request $request, Payment $payment): JsonResponse
    {
        if (! config('billing.sandbox')) {
            abort(404);
        }

        if ($payment->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($payment->status !== 'pending') {
            return response()->json(
                $this->sandboxCompletePayload($request->user(), 'Payment already processed.')
            );
        }

        $this->subscriptions->markPaymentCompleted($payment, [
            'provider_reference' => 'SANDBOX-'.$payment->txn_ref,
            'status' => 'completed',
            'sandbox' => true,
        ]);

        return response()->json(
            $this->sandboxCompletePayload($request->user(), 'Sandbox payment completed.')
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sandboxCompletePayload(\App\Models\User $user, string $message): array
    {
        $subscription = $this->subscriptions->currentSubscription($user);

        return [
            'message' => $message,
            'subscription' => $this->formatSubscription($subscription),
            'trial' => $this->subscriptions->trialStatus($user),
            'addons' => $this->subscriptions->addonStatuses($user),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatSubscription(?\App\Models\Subscription $subscription): ?array
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
            'active' => $subscription->isActive(),
        ];
    }
}

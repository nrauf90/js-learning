<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\Billing\GatewayResolver;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillingCallbackController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private GatewayResolver $gateways,
    ) {}

    public function jazzCashReturn(Request $request): RedirectResponse
    {
        return $this->handleCallback('jazzcash', $request->all(), false);
    }

    public function jazzCashIpn(Request $request): JsonResponse
    {
        return $this->handleCallbackJson('jazzcash', $request->all());
    }

    public function easyPaisaReturn(Request $request): RedirectResponse
    {
        return $this->handleCallback('easypaisa', $request->all(), false);
    }

    public function easyPaisaIpn(Request $request): JsonResponse
    {
        return $this->handleCallbackJson('easypaisa', $request->all());
    }

    private function handleCallback(string $provider, array $payload, bool $json): RedirectResponse|JsonResponse
    {
        $gateway = $this->gateways->resolve($provider);
        $result = $this->processPayment($gateway, $payload);

        if ($json) {
            return response()->json($result);
        }

        $status = $result['success'] ? 'success' : 'failed';

        return redirect()->away(
            config('billing.frontend_billing_url').'?status='.$status.'&txn='.urlencode($result['txn_ref'] ?? '')
        );
    }

    private function handleCallbackJson(string $provider, array $payload): JsonResponse
    {
        $gateway = $this->gateways->resolve($provider);

        return response()->json($this->processPayment($gateway, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, txn_ref: ?string, message: string}
     */
    private function processPayment(\App\Contracts\PaymentGateway $gateway, array $payload): array
    {
        if (! $gateway->verifyCallback($payload)) {
            return ['success' => false, 'txn_ref' => null, 'message' => 'Invalid signature'];
        }

        $txnRef = $gateway->extractTxnRef($payload);
        if (! $txnRef) {
            return ['success' => false, 'txn_ref' => null, 'message' => 'Missing transaction reference'];
        }

        $payment = Payment::query()->where('txn_ref', $txnRef)->first();
        if (! $payment) {
            return ['success' => false, 'txn_ref' => $txnRef, 'message' => 'Payment not found'];
        }

        if ($payment->status === 'completed') {
            return ['success' => true, 'txn_ref' => $txnRef, 'message' => 'Already processed'];
        }

        if ($gateway->isSuccessful($payload)) {
            $this->subscriptions->markPaymentCompleted($payment, [
                'provider_reference' => $gateway->extractProviderReference($payload),
                'raw' => $payload,
            ]);

            return ['success' => true, 'txn_ref' => $txnRef, 'message' => 'Subscription activated'];
        }

        $this->subscriptions->markPaymentFailed($payment, ['raw' => $payload]);

        return ['success' => false, 'txn_ref' => $txnRef, 'message' => 'Payment failed'];
    }
}

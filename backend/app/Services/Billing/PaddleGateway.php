<?php

namespace App\Services\Billing;

use App\Contracts\BillingProvider;
use App\Exceptions\BillingProviderException;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;

class PaddleGateway implements BillingProvider
{
    public function __construct(private PaddleClient $client) {}

    public function provider(): string
    {
        return 'paddle';
    }

    public function createCheckoutUrl(User $user, Payment $payment): string
    {
        $priceId = config("billing.plans.{$payment->plan}.price_id");

        if (blank($priceId)) {
            throw new BillingProviderException(
                "No Paddle price id configured for plan '{$payment->plan}'.",
                'This plan is not available for purchase yet.',
            );
        }

        $customerId = $this->resolveCustomerId($user);

        $body = [
            'items' => [
                ['price_id' => $priceId, 'quantity' => 1],
            ],
            'customer_id' => $customerId,
            // Echoed back on every transaction.* and subscription.* webhook.
            // txn_ref is how we find our own pending Payment row without
            // trusting anything the browser sent back.
            'custom_data' => [
                'user_id' => (string) $user->id,
                'payment_id' => (string) $payment->id,
                'txn_ref' => $payment->txn_ref,
                'plan' => $payment->plan,
            ],
        ];

        // Null tells Paddle to use the default payment link from the dashboard.
        // Only send the key when we have an approved domain configured.
        if (filled($checkoutUrl = config('billing.paddle.checkout_url'))) {
            $body['checkout'] = ['url' => $checkoutUrl];
        }

        $transaction = $this->client->createTransaction($body);

        $payment->forceFill([
            'external_transaction_id' => $transaction['id'] ?? null,
        ])->save();

        $url = data_get($transaction, 'checkout.url');

        if (blank($url)) {
            throw new BillingProviderException(
                'Paddle returned a transaction without a checkout URL. Set a default payment '
                .'link under Checkout > Checkout settings in the Paddle dashboard.',
            );
        }

        return $url;
    }

    /**
     * Verify a `Paddle-Signature` header of the form `ts=<unix>;h1=<hex>` over
     * the exact bytes Paddle POSTed. Fails closed when unconfigured — the whole
     * point of this check is that an unsigned request can activate a paid
     * subscription for free.
     */
    public function verifyWebhook(string $rawBody, ?string $signatureHeader): bool
    {
        $secret = config('billing.paddle.webhook_secret');

        if (blank($secret) || blank($signatureHeader)) {
            return false;
        }

        $parts = [];
        foreach (explode(';', $signatureHeader) as $segment) {
            $pair = explode('=', trim($segment), 2);
            if (count($pair) === 2) {
                $parts[$pair[0]] = $pair[1];
            }
        }

        $timestamp = $parts['ts'] ?? null;
        $signature = $parts['h1'] ?? null;

        if (blank($timestamp) || blank($signature) || ! ctype_digit((string) $timestamp)) {
            return false;
        }

        $tolerance = (int) config('billing.paddle.webhook_tolerance', 300);
        if ($tolerance > 0 && abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$rawBody, (string) $secret);

        return hash_equals($expected, $signature);
    }

    public function customerPortalUrl(User $user): ?string
    {
        if (blank($user->paddle_customer_id)) {
            return null;
        }

        $subscriptionIds = Subscription::query()
            ->where('user_id', $user->id)
            ->where('provider', $this->provider())
            ->whereNotNull('external_id')
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->pluck('external_id')
            ->all();

        $session = $this->client->createPortalSession($user->paddle_customer_id, $subscriptionIds);

        return data_get($session, 'urls.general.overview');
    }

    public function cancelSubscription(string $externalSubscriptionId, bool $immediately = false): void
    {
        $this->client->cancelSubscription($externalSubscriptionId, $immediately);
    }

    private function resolveCustomerId(User $user): string
    {
        if (filled($user->paddle_customer_id)) {
            return $user->paddle_customer_id;
        }

        $customerId = $this->client->findOrCreateCustomer($user->email, $user->name);
        $user->setPaddleCustomerId($customerId);

        return $customerId;
    }
}

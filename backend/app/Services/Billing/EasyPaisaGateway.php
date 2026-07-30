<?php

namespace App\Services\Billing;

use App\Contracts\PaymentGateway;
use App\Models\Payment;

class EasyPaisaGateway implements PaymentGateway
{
    public function provider(): string
    {
        return 'easypaisa';
    }

    public function buildCheckout(Payment $payment): array
    {
        $config = config('billing.providers.easypaisa');

        if ($this->useSandbox()) {
            return [
                'method' => 'POST',
                'action_url' => rtrim(config('app.url'), '/').'/api/billing/sandbox/complete/'.$payment->id,
                'fields' => [
                    'txn_ref' => $payment->txn_ref,
                ],
                'sandbox' => true,
            ];
        }

        $fields = [
            'storeId' => $config['store_id'],
            'orderId' => $payment->txn_ref,
            'transactionAmount' => number_format((float) $payment->amount, 2, '.', ''),
            'transactionType' => 'MA',
            'mobileAccountNo' => '',
            'emailAddress' => $payment->user->email,
            'returnUrl' => $config['return_url'],
        ];

        $fields['secureHash'] = $this->secureHash($fields, $config['hash_key']);

        return [
            'method' => 'POST',
            'action_url' => $config['checkout_url'],
            'fields' => $fields,
            'sandbox' => false,
        ];
    }

    public function verifyCallback(array $payload): bool
    {
        if ($this->useSandbox()) {
            return true;
        }

        $received = $payload['secureHash'] ?? $payload['hash'] ?? null;
        if (! $received) {
            return false;
        }

        $key = config('billing.providers.easypaisa.hash_key');
        $copy = $payload;
        unset($copy['secureHash'], $copy['hash']);

        return hash_equals(strtolower($received), strtolower($this->secureHash($copy, $key)));
    }

    public function extractTxnRef(array $payload): ?string
    {
        return $payload['orderId'] ?? $payload['order_id'] ?? $payload['txn_ref'] ?? null;
    }

    public function extractProviderReference(array $payload): ?string
    {
        return $payload['transactionId'] ?? $payload['transaction_id'] ?? null;
    }

    public function isSuccessful(array $payload): bool
    {
        $status = strtolower((string) ($payload['status'] ?? $payload['responseCode'] ?? ''));

        return in_array($status, ['completed', '0000', 'success'], true);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function secureHash(array $fields, string $key): string
    {
        ksort($fields);
        $string = $key.'&'.implode('&', array_values($fields));

        return hash_hmac('sha256', $string, $key);
    }

    private function useSandbox(): bool
    {
        if (! config('billing.sandbox')) {
            return false;
        }

        return empty(config('billing.providers.easypaisa.store_id'));
    }
}

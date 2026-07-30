<?php

namespace App\Services\Billing;

use App\Contracts\PaymentGateway;
use App\Models\Payment;

class JazzCashGateway implements PaymentGateway
{
    public function provider(): string
    {
        return 'jazzcash';
    }

    public function buildCheckout(Payment $payment): array
    {
        $config = config('billing.providers.jazzcash');
        $amountPaisa = (int) round((float) $payment->amount * 100);

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
            'pp_Version' => '1.1',
            'pp_TxnType' => 'MWALLET',
            'pp_Language' => 'EN',
            'pp_MerchantID' => $config['merchant_id'],
            'pp_Password' => $config['password'],
            'pp_TxnRefNo' => $payment->txn_ref,
            'pp_Amount' => str_pad((string) $amountPaisa, 12, '0', STR_PAD_LEFT),
            'pp_TxnCurrency' => 'PKR',
            'pp_TxnDateTime' => now()->format('YmdHis'),
            'pp_TxnExpiryDateTime' => now()->addHours(2)->format('YmdHis'),
            'pp_BillReference' => 'plan:'.$payment->plan,
            'pp_Description' => 'Cashflow '.$payment->plan,
            'pp_ReturnURL' => $config['return_url'],
            'pp_IPNURL' => $config['ipn_url'],
        ];

        $fields['pp_SecureHash'] = $this->secureHash($fields, $config['integrity_salt']);

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

        $received = $payload['pp_SecureHash'] ?? $payload['secure_hash'] ?? null;
        if (! $received) {
            return false;
        }

        $salt = config('billing.providers.jazzcash.integrity_salt');
        $copy = $payload;
        unset($copy['pp_SecureHash'], $copy['secure_hash']);

        return hash_equals(strtolower($received), strtolower($this->secureHash($copy, $salt)));
    }

    public function extractTxnRef(array $payload): ?string
    {
        return $payload['pp_TxnRefNo'] ?? $payload['txn_ref'] ?? null;
    }

    public function extractProviderReference(array $payload): ?string
    {
        return $payload['pp_TxnRefNo'] ?? $payload['pp_RetreivalReferenceNo'] ?? null;
    }

    public function isSuccessful(array $payload): bool
    {
        $code = $payload['pp_ResponseCode'] ?? $payload['response_code'] ?? null;

        return in_array((string) $code, ['000', '121', '200'], true)
            || ($payload['status'] ?? null) === 'completed';
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function secureHash(array $fields, string $salt): string
    {
        ksort($fields);
        $values = array_filter($fields, fn ($v) => $v !== null && $v !== '');
        $string = $salt.'&'.implode('&', array_values($values));

        return hash_hmac('sha256', $string, $salt);
    }

    private function useSandbox(): bool
    {
        if (! config('billing.sandbox')) {
            return false;
        }

        return empty(config('billing.providers.jazzcash.merchant_id'));
    }
}

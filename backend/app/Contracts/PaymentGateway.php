<?php

namespace App\Contracts;

use App\Models\Payment;

interface PaymentGateway
{
    public function provider(): string;

    /**
     * @return array{
     *     method: string,
     *     action_url: string,
     *     fields: array<string, string>,
     *     sandbox?: bool
     * }
     */
    public function buildCheckout(Payment $payment): array;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function verifyCallback(array $payload): bool;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractTxnRef(array $payload): ?string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function extractProviderReference(array $payload): ?string;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function isSuccessful(array $payload): bool;
}

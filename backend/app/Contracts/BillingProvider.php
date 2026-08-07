<?php

namespace App\Contracts;

use App\Models\Payment;
use App\Models\User;

/**
 * Contract for a merchant-of-record billing provider (Paddle).
 *
 * Replaces the old PaymentGateway contract, which assumed the browser would
 * POST a form of merchant credentials straight to the gateway. Hosted-checkout
 * providers hand back a URL instead, and they sign the exact bytes of their
 * webhook body — so verification takes a raw string, never a parsed array.
 */
interface BillingProvider
{
    public function provider(): string;

    /**
     * Create a hosted checkout for a pending payment and return the URL the
     * browser should be navigated to.
     */
    public function createCheckoutUrl(User $user, Payment $payment): string;

    /**
     * Verify a webhook against the raw request body and signature header.
     * Must fail closed when no signing secret is configured.
     */
    public function verifyWebhook(string $rawBody, ?string $signatureHeader): bool;

    /**
     * URL of the provider-hosted portal where a customer updates their payment
     * method, views invoices and cancels. Null when the user has no provider
     * customer record yet.
     */
    public function customerPortalUrl(User $user): ?string;

    /**
     * Schedule a cancellation. Providers cancel at period end by default so the
     * customer keeps access they already paid for.
     */
    public function cancelSubscription(string $externalSubscriptionId, bool $immediately = false): void;
}

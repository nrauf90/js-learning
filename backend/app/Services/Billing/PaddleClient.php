<?php

namespace App\Services\Billing;

use App\Exceptions\BillingProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin HTTP wrapper over the Paddle Billing API.
 *
 * Deliberately not retried: every call here except the lookups is a create, and
 * Paddle has no idempotency key on these endpoints — a blind retry on a timed
 * out POST /transactions would bill nobody twice, but it would litter the
 * account with orphan transactions and hand back the wrong checkout URL.
 */
class PaddleClient
{
    public function isConfigured(): bool
    {
        return filled(config('billing.paddle.api_key'));
    }

    public function baseUrl(): string
    {
        return config('billing.paddle.environment') === 'production'
            ? 'https://api.paddle.com'
            : 'https://sandbox-api.paddle.com';
    }

    /**
     * Resolve a Paddle customer for this email, creating one if needed.
     *
     * Paddle rejects a duplicate email with 409 `customer_already_exists`
     * rather than returning the existing record, so the conflict is a normal
     * branch here, not an error — it happens for every returning customer whose
     * local `paddle_customer_id` was never stored.
     */
    public function findOrCreateCustomer(string $email, ?string $name = null): string
    {
        $create = $this->send('post', '/customers', array_filter([
            'email' => $email,
            'name' => $name,
        ]));

        if ($create->successful()) {
            return (string) $create->json('data.id');
        }

        if ($create->status() === 409) {
            $existing = $this->findCustomerByEmail($email);

            if ($existing !== null) {
                return $existing;
            }
        }

        $this->fail('create customer', $create);
    }

    public function findCustomerByEmail(string $email): ?string
    {
        $response = $this->send('get', '/customers', ['email' => $email]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('data.0.id');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTransaction(array $payload): array
    {
        $response = $this->send('post', '/transactions', $payload);

        if (! $response->successful()) {
            $this->fail('create transaction', $response);
        }

        return $response->json('data') ?? [];
    }

    /**
     * Portal session URLs are single-use and short-lived — Paddle is explicit
     * that they must be minted per request and never cached.
     *
     * @param  list<string>  $subscriptionIds
     * @return array<string, mixed>
     */
    public function createPortalSession(string $customerId, array $subscriptionIds = []): array
    {
        $response = $this->send(
            'post',
            "/customers/{$customerId}/portal-sessions",
            $subscriptionIds === [] ? [] : ['subscription_ids' => array_values($subscriptionIds)],
        );

        if (! $response->successful()) {
            $this->fail('create portal session', $response);
        }

        return $response->json('data') ?? [];
    }

    public function cancelSubscription(string $subscriptionId, bool $immediately = false): void
    {
        $response = $this->send('post', "/subscriptions/{$subscriptionId}/cancel", [
            'effective_from' => $immediately ? 'immediately' : 'next_billing_period',
        ]);

        if (! $response->successful()) {
            $this->fail('cancel subscription', $response);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSubscription(string $subscriptionId): ?array
    {
        $response = $this->send('get', "/subscriptions/{$subscriptionId}");

        return $response->successful() ? $response->json('data') : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $path, array $data = []): Response
    {
        try {
            return $this->request()->{$method}($path, $data);
        } catch (ConnectionException $e) {
            throw new BillingProviderException("Paddle {$method} {$path} failed: ".$e->getMessage());
        }
    }

    private function request(): PendingRequest
    {
        if (! $this->isConfigured()) {
            throw new BillingProviderException(
                'PADDLE_API_KEY is not set. Set it, or turn on BILLING_SANDBOX for local development.'
            );
        }

        return Http::baseUrl($this->baseUrl())
            ->withToken((string) config('billing.paddle.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('billing.paddle.api_timeout', 15));
    }

    /**
     * Log the provider's error detail server-side and raise a generic failure —
     * Paddle error bodies name the seller account and internal entity ids.
     */
    private function fail(string $action, Response $response): never
    {
        Log::error("Paddle: failed to {$action}", [
            'status' => $response->status(),
            'error' => $response->json('error'),
        ]);

        throw new BillingProviderException(
            "Paddle: failed to {$action} (HTTP {$response->status()})"
        );
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Billing\PaddleGateway;
use App\Services\Billing\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single entry point for Paddle notifications. This is the only path that may
 * grant paid access — the browser's post-checkout redirect is cosmetic and
 * carries nothing we trust.
 */
class PaddleWebhookController extends Controller
{
    public function __construct(
        private SubscriptionService $subscriptions,
        private PaddleGateway $paddle,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // Must be the raw bytes: Paddle signs the body exactly as sent, so
        // anything that round-trips through json_decode/encode will not verify.
        $rawBody = $request->getContent();

        if (! $this->paddle->verifyWebhook($rawBody, $request->header('Paddle-Signature'))) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event) || blank($event['event_id'] ?? null) || blank($event['event_type'] ?? null)) {
            return response()->json(['message' => 'Malformed payload'], 400);
        }

        // Claim the event. The unique index on (provider, event_id) is what
        // actually makes this safe against Paddle's redelivery — two concurrent
        // deliveries race here and exactly one wins.
        try {
            $record = WebhookEvent::create([
                'provider' => 'paddle',
                'event_id' => $event['event_id'],
                'event_type' => $event['event_type'],
                'payload' => $event,
            ]);
        } catch (QueryException) {
            return response()->json(['message' => 'Already processed']);
        }

        try {
            $result = $this->dispatchEvent($event['event_type'], $event['data'] ?? []);
        } catch (\Throwable $e) {
            // Release the claim so Paddle's retry gets a real second attempt
            // instead of being deduped against a row we never processed.
            $record->delete();

            throw $e;
        }

        $record->update(['processed_at' => now(), 'result' => Str::limit($result, 250)]);

        return response()->json(['message' => $result]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatchEvent(string $type, array $data): string
    {
        // Every subscription.* event carries the full subscription entity, so
        // one sync path covers created/updated/activated/trialing/past_due/
        // paused/resumed/canceled — including event types Paddle adds later.
        if (str_starts_with($type, 'subscription.')) {
            return $this->syncSubscription($data);
        }

        return match ($type) {
            'transaction.completed' => $this->transactionCompleted($data),
            'transaction.payment_failed' => $this->transactionFailed($data),
            'adjustment.created', 'adjustment.updated' => $this->adjustment($data),
            default => 'Ignored',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncSubscription(array $data): string
    {
        $user = $this->resolveUser($data);

        if (! $user) {
            return 'No matching user';
        }

        $priceId = data_get($data, 'items.0.price.id');

        $this->subscriptions->syncSubscription($user, [
            'provider' => 'paddle',
            'external_id' => $data['id'],
            'external_price_id' => $priceId,
            'plan' => $this->resolvePlan($priceId, $data),
            'status' => $data['status'] ?? 'active',
            'starts_at' => $this->date($data['started_at'] ?? $data['first_billed_at'] ?? null),
            // Access runs to the end of the period that has been paid for.
            'ends_at' => $this->date(
                data_get($data, 'current_billing_period.ends_at') ?? ($data['next_billed_at'] ?? null)
            ),
            'renews_at' => $this->date($data['next_billed_at'] ?? null),
            'trial_ends_at' => $this->date(data_get($data, 'items.0.trial_dates.ends_at')),
            'canceled_at' => $this->date($data['canceled_at'] ?? null),
            'paused_at' => $this->date($data['paused_at'] ?? null),
            // Paddle keeps a cancelling subscription `active` and records the
            // intent as a scheduled change until the period actually ends.
            'cancel_at_period_end' => data_get($data, 'scheduled_change.action') === 'cancel',
        ]);

        return 'Subscription synced';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transactionCompleted(array $data): string
    {
        $user = $this->resolveUser($data);

        if (! $user) {
            return 'No matching user';
        }

        $attributes = [
            'provider_reference' => $data['id'] ?? null,
            'external_transaction_id' => $data['id'] ?? null,
            'external_subscription_id' => $data['subscription_id'] ?? null,
            'invoice_number' => $data['invoice_number'] ?? null,
        ];

        $payment = $this->findPayment($data, $user);

        if (! $payment) {
            // A renewal: Paddle raises the transaction itself, so there is no
            // pending row we created at checkout. Record it so the payment
            // history and admin ledger stay complete.
            $payment = Payment::create([
                'user_id' => $user->id,
                'provider' => 'paddle',
                'plan' => $this->resolvePlan(data_get($data, 'items.0.price.id'), $data),
                'amount' => $this->minorUnitsToDecimal(data_get($data, 'details.totals.total')),
                'currency' => $data['currency_code'] ?? config('billing.currency', 'USD'),
                'status' => 'pending',
                'txn_ref' => 'CF-'.Str::upper(Str::random(12)),
            ]);
        }

        $this->subscriptions->recordPaymentCompleted($payment, $attributes);

        return 'Payment recorded';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function transactionFailed(array $data): string
    {
        $user = $this->resolveUser($data);
        $payment = $user ? $this->findPayment($data, $user) : null;

        if (! $payment) {
            return 'No matching payment';
        }

        $this->subscriptions->markPaymentFailed($payment, [
            'external_transaction_id' => $data['id'] ?? null,
        ]);

        return 'Payment failed';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function adjustment(array $data): string
    {
        if (($data['action'] ?? null) !== 'refund') {
            return 'Ignored';
        }

        $payment = Payment::query()
            ->where('external_transaction_id', $data['transaction_id'] ?? '')
            ->first();

        if (! $payment) {
            return 'No matching payment';
        }

        $this->subscriptions->markPaymentRefunded(
            $payment,
            $this->minorUnitsToDecimal(data_get($data, 'totals.total')),
            ['adjustment_id' => $data['id'] ?? null, 'reason' => $data['reason'] ?? null],
        );

        return 'Refund recorded';
    }

    /**
     * Match the local pending Payment created at checkout. `txn_ref` comes back
     * in custom_data, which we set ourselves — preferred over anything derived
     * from provider ids that a renewal would not carry.
     *
     * @param  array<string, mixed>  $data
     */
    private function findPayment(array $data, User $user): ?Payment
    {
        $txnRef = data_get($data, 'custom_data.txn_ref');

        if (filled($txnRef)) {
            $payment = Payment::query()
                ->where('user_id', $user->id)
                ->where('txn_ref', $txnRef)
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        return Payment::query()
            ->where('external_transaction_id', $data['id'] ?? '')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveUser(array $data): ?User
    {
        $userId = data_get($data, 'custom_data.user_id');

        if (filled($userId) && $user = User::find($userId)) {
            return $user;
        }

        $customerId = $data['customer_id'] ?? null;

        if (blank($customerId)) {
            return null;
        }

        return User::query()->where('paddle_customer_id', $customerId)->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePlan(?string $priceId, array $data): string
    {
        foreach (config('billing.plans') as $key => $plan) {
            if (filled($plan['price_id'] ?? null) && $plan['price_id'] === $priceId) {
                return $key;
            }
        }

        $custom = data_get($data, 'custom_data.plan');

        if (is_string($custom) && array_key_exists($custom, config('billing.plans'))) {
            return $custom;
        }

        // Someone paid for a price this deployment does not know about — most
        // likely a price id added in Paddle but not in the env. Grant the base
        // plan rather than dropping a paid subscription on the floor, and make
        // the mismatch loud.
        Log::warning('Paddle: unmapped price id, defaulting to monthly', ['price_id' => $priceId]);

        return 'monthly';
    }

    private function date(?string $value): ?Carbon
    {
        return filled($value) ? Carbon::parse($value) : null;
    }

    /**
     * Paddle sends money as a string of minor units ("1499" = $14.99).
     */
    private function minorUnitsToDecimal(mixed $value): float
    {
        return round(((int) $value) / 100, 2);
    }
}

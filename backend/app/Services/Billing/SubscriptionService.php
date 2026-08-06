<?php

namespace App\Services\Billing;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class SubscriptionService
{
    public function createPendingPayment(User $user, string $plan, string $provider): Payment
    {
        $planConfig = config("billing.plans.{$plan}");
        if (! $planConfig) {
            throw new \InvalidArgumentException("Unknown plan: {$plan}");
        }

        return Payment::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'plan' => $plan,
            'amount' => $planConfig['amount'],
            'currency' => config('billing.currency', 'USD'),
            'status' => 'pending',
            'txn_ref' => 'CF-'.Str::upper(Str::random(12)),
        ]);
    }

    /**
     * Record a completed payment without touching subscription state.
     *
     * Under a merchant-of-record provider the subscription lifecycle is driven
     * entirely by `subscription.*` webhooks — a completed transaction is just
     * the money moving. Deriving period end from the payment as well would let
     * the two sources of truth drift apart on renewals, proration and refunds.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordPaymentCompleted(Payment $payment, array $attributes = []): Payment
    {
        $payment->update([
            'status' => 'completed',
            'provider_reference' => $attributes['provider_reference'] ?? $payment->provider_reference,
            'external_transaction_id' => $attributes['external_transaction_id'] ?? $payment->external_transaction_id,
            'external_subscription_id' => $attributes['external_subscription_id'] ?? $payment->external_subscription_id,
            'invoice_number' => $attributes['invoice_number'] ?? $payment->invoice_number,
            'payload' => array_merge($payment->payload ?? [], ['callback' => $attributes]),
        ]);

        return $payment->fresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markPaymentFailed(Payment $payment, array $attributes = []): void
    {
        $payment->update([
            'status' => 'failed',
            'payload' => array_merge($payment->payload ?? [], ['callback' => $attributes]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function markPaymentRefunded(Payment $payment, float $refundedAmount, array $attributes = []): void
    {
        $payment->update([
            'status' => $refundedAmount >= (float) $payment->amount ? 'refunded' : 'partially_refunded',
            'refunded_amount' => $refundedAmount,
            'payload' => array_merge($payment->payload ?? [], ['refund' => $attributes]),
        ]);
    }

    /**
     * Upsert local subscription state from the provider's own view of it.
     *
     * Keyed on (provider, external_id) so a redelivered webhook updates the
     * same row. Note this *sets* `ends_at` rather than extending it: the
     * provider owns the billing period, and adding an interval on every renewal
     * webhook would push the end date further out on each retry.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function syncSubscription(User $user, array $attributes): Subscription|SubscriptionAddon
    {
        if (($attributes['plan'] ?? null) === 'receipt_addon') {
            return $this->syncAddon($user, $attributes);
        }

        $subscription = Subscription::query()
            ->where('provider', $attributes['provider'])
            ->where('external_id', $attributes['external_id'])
            ->first() ?? new Subscription;

        $subscription->fill([
            'user_id' => $user->id,
            'provider' => $attributes['provider'],
            'external_id' => $attributes['external_id'],
            'external_price_id' => $attributes['external_price_id'] ?? null,
            'plan' => $attributes['plan'] ?? $subscription->plan ?? 'monthly',
            'status' => $attributes['status'],
            'starts_at' => $attributes['starts_at'] ?? $subscription->starts_at,
            'ends_at' => $attributes['ends_at'] ?? $subscription->ends_at,
            'renews_at' => $attributes['renews_at'] ?? null,
            'trial_ends_at' => $attributes['trial_ends_at'] ?? null,
            'canceled_at' => $attributes['canceled_at'] ?? null,
            'cancel_at_period_end' => (bool) ($attributes['cancel_at_period_end'] ?? false),
            'paused_at' => $attributes['paused_at'] ?? null,
        ]);
        $subscription->save();

        // Attach any payments that arrived before we knew the subscription id.
        Payment::query()
            ->where('user_id', $user->id)
            ->where('external_subscription_id', $attributes['external_id'])
            ->whereNull('subscription_id')
            ->update(['subscription_id' => $subscription->id]);

        return $subscription->fresh();
    }

    /**
     * Local, provider-free activation. Only reachable while `billing.sandbox`
     * is on — it backs local development and the Playwright harness so neither
     * needs live Paddle credentials.
     */
    public function activateLocally(Payment $payment): Subscription|SubscriptionAddon
    {
        if ($payment->plan === 'receipt_addon') {
            return $this->activateLocalAddon($payment);
        }

        $planConfig = config("billing.plans.{$payment->plan}");
        $user = $payment->user;

        $existing = Subscription::query()
            ->where('user_id', $user->id)
            ->where('plan', $payment->plan)
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();

        $startsAt = now();
        $endsAt = $this->calculateEndsAt($startsAt, $planConfig['interval'], (int) $planConfig['interval_count']);

        if ($existing) {
            $startsAt = $existing->starts_at ?? now();
            $base = $existing->ends_at && $existing->ends_at->isFuture()
                ? $existing->ends_at->copy()
                : now();
            $endsAt = $this->calculateEndsAt($base, $planConfig['interval'], (int) $planConfig['interval_count']);

            $existing->update([
                'ends_at' => $endsAt,
                'renews_at' => $endsAt,
                'status' => 'active',
            ]);

            $payment->update(['subscription_id' => $existing->id]);

            return $existing->fresh();
        }

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'provider' => 'manual',
            'plan' => $payment->plan,
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'renews_at' => $endsAt,
        ]);

        $payment->update(['subscription_id' => $subscription->id]);

        return $subscription;
    }

    public function currentSubscription(User $user): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->whereIn('plan', ['monthly', 'yearly'])
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();
    }

    public function trialEndsAt(User $user): Carbon
    {
        $days = max(1, (int) config('billing.trial_days', 7));

        return $user->created_at->copy()->addDays($days);
    }

    public function isOnTrial(User $user): bool
    {
        if ($this->currentSubscription($user)) {
            return false;
        }

        return now()->lt($this->trialEndsAt($user));
    }

    public function hasAccess(User $user): bool
    {
        return $this->currentSubscription($user) !== null || $this->isOnTrial($user);
    }

    /**
     * @return array{active: bool, ends_at: string|null, days_remaining: int, expired: bool}
     */
    public function trialStatus(User $user): array
    {
        $endsAt = $this->trialEndsAt($user);
        $active = $this->isOnTrial($user);
        $daysRemaining = 0;

        if ($active) {
            $daysRemaining = max(
                0,
                (int) now()->copy()->startOfDay()->diffInDays($endsAt->copy()->startOfDay(), false)
            );
        }

        $expired = ! $active && $this->currentSubscription($user) === null
            && now()->gte($endsAt);

        return [
            'active' => $active,
            'ends_at' => $endsAt->toIso8601String(),
            'days_remaining' => $daysRemaining,
            'expired' => $expired,
        ];
    }

    public function currentAddon(User $user, string $addonKey): ?SubscriptionAddon
    {
        return SubscriptionAddon::query()
            ->where('user_id', $user->id)
            ->where('addon_key', $addonKey)
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function addonStatuses(User $user): array
    {
        $receipt = $this->currentAddon($user, 'receipt');

        return [
            'receipt' => [
                'active' => $receipt?->isActive() ?? false,
                'ends_at' => $receipt?->ends_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncAddon(User $user, array $attributes): SubscriptionAddon
    {
        $addon = SubscriptionAddon::query()
            ->where('provider', $attributes['provider'])
            ->where('external_id', $attributes['external_id'])
            ->first() ?? new SubscriptionAddon;

        $addon->fill([
            'user_id' => $user->id,
            'provider' => $attributes['provider'],
            'external_id' => $attributes['external_id'],
            'addon_key' => 'receipt',
            'status' => $attributes['status'],
            'ends_at' => $attributes['ends_at'] ?? $addon->ends_at,
            'cancel_at_period_end' => (bool) ($attributes['cancel_at_period_end'] ?? false),
        ]);
        $addon->save();

        return $addon->fresh();
    }

    private function activateLocalAddon(Payment $payment): SubscriptionAddon
    {
        $planConfig = config("billing.plans.{$payment->plan}");
        $user = $payment->user;
        $addonKey = 'receipt';

        $existing = SubscriptionAddon::query()
            ->where('user_id', $user->id)
            ->where('addon_key', $addonKey)
            ->whereIn('status', Subscription::ACTIVE_STATUSES)
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();

        $endsAt = $this->calculateEndsAt(
            $existing && $existing->ends_at?->isFuture() ? $existing->ends_at->copy() : now(),
            $planConfig['interval'],
            (int) $planConfig['interval_count']
        );

        if ($existing) {
            $existing->update([
                'ends_at' => $endsAt,
                'status' => 'active',
            ]);

            return $existing->fresh();
        }

        return SubscriptionAddon::create([
            'user_id' => $user->id,
            'provider' => 'manual',
            'addon_key' => $addonKey,
            'status' => 'active',
            'ends_at' => $endsAt,
        ]);
    }

    private function calculateEndsAt(Carbon $from, string $interval, int $count): Carbon
    {
        return match ($interval) {
            'year' => $from->copy()->addYears($count),
            'month' => $from->copy()->addMonths($count),
            default => $from->copy()->addMonths($count),
        };
    }
}

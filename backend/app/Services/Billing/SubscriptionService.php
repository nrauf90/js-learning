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
            'currency' => config('billing.currency', 'PKR'),
            'status' => 'pending',
            'txn_ref' => 'CF-'.Str::upper(Str::random(12)),
        ]);
    }

    public function markPaymentCompleted(Payment $payment, array $payload): Subscription|SubscriptionAddon
    {
        $payment->update([
            'status' => 'completed',
            'provider_reference' => $payload['provider_reference'] ?? $payment->provider_reference,
            'payload' => array_merge($payment->payload ?? [], ['callback' => $payload]),
        ]);

        return $this->activateSubscription($payment->fresh());
    }

    public function markPaymentFailed(Payment $payment, array $payload): void
    {
        $payment->update([
            'status' => 'failed',
            'payload' => array_merge($payment->payload ?? [], ['callback' => $payload]),
        ]);
    }

    public function activateSubscription(Payment $payment): Subscription|SubscriptionAddon
    {
        if ($payment->plan === 'receipt_addon') {
            return $this->activateAddon($payment);
        }

        $planConfig = config("billing.plans.{$payment->plan}");
        $user = $payment->user;

        $existing = Subscription::query()
            ->where('user_id', $user->id)
            ->where('plan', $payment->plan)
            ->where('status', 'active')
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
                'status' => 'active',
            ]);

            $payment->update(['subscription_id' => $existing->id]);

            return $existing->fresh();
        }

        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => $payment->plan,
            'status' => 'active',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $payment->update(['subscription_id' => $subscription->id]);

        return $subscription;
    }

    public function currentSubscription(User $user): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->whereIn('plan', ['monthly', 'yearly'])
            ->where('status', 'active')
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
            ->where('status', 'active')
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

    private function activateAddon(Payment $payment): SubscriptionAddon
    {
        $planConfig = config("billing.plans.{$payment->plan}");
        $user = $payment->user;
        $addonKey = 'receipt';

        $existing = SubscriptionAddon::query()
            ->where('user_id', $user->id)
            ->where('addon_key', $addonKey)
            ->where('status', 'active')
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

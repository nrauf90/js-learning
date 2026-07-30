<?php

namespace Tests\Concerns;

use App\Models\Subscription;
use App\Models\SubscriptionAddon;
use App\Models\User;
use Carbon\Carbon;

trait CreatesSubscribedUser
{
    protected function subscribeUser(User $user, string $plan = 'monthly', ?Carbon $endsAt = null): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'plan' => $plan,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => $endsAt ?? now()->addMonth(),
        ]);
    }

    protected function activateReceiptAddon(User $user, ?Carbon $endsAt = null): SubscriptionAddon
    {
        return SubscriptionAddon::create([
            'user_id' => $user->id,
            'addon_key' => 'receipt',
            'status' => 'active',
            'ends_at' => $endsAt ?? now()->addMonth(),
        ]);
    }

    protected function expireTrial(User $user): void
    {
        $days = max(1, (int) config('billing.trial_days', 7));
        $user->forceFill(['created_at' => now()->subDays($days + 1)])->save();
    }
}

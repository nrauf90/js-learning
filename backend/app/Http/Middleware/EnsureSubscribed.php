<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscribed
{
    public function __construct(private SubscriptionService $subscriptions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // The subscription belongs to the shop, not to each person working the
        // till. A staff account has its own `created_at` and so its own 7-day
        // trial clock, which would otherwise lapse a week after hiring and lock
        // a paid-up shop's cashier out mid-shift.
        $payer = self::payingAccount($user);

        if (! $this->subscriptions->hasAccess($payer)) {
            $trial = $this->subscriptions->trialStatus($payer);

            // Shops cannot buy their own subscription any more, so telling them
            // to subscribe describes an action they have no way to take. Point
            // them at the person who can actually do it.
            return response()->json([
                'message' => $trial['expired']
                    ? 'Your free trial has ended. Please contact the administrator to activate your subscription.'
                    : 'Your subscription has ended. Please contact the administrator to renew it.',
                'code' => $trial['expired'] ? 'trial_expired' : 'subscription_required',
            ], 402);
        }

        return $next($request);
    }

    /**
     * Whoever's subscription covers this request. For staff that is the shop
     * owner; for everyone else it is themselves. Falls back to the user when
     * the owner cannot be resolved, so a broken shop link degrades to the old
     * per-account behaviour rather than locking the account out entirely.
     *
     * Public because BillingController must answer "is this shop lapsed?" with
     * exactly the same account this gate judges — reporting per-account there
     * told a paid shop's cashier their trial had expired while the API served
     * them normally.
     */
    public static function payingAccount(User $user): User
    {
        $ownerId = $user->dataOwnerId();

        if ($ownerId === (int) $user->id) {
            return $user;
        }

        return User::find($ownerId) ?? $user;
    }
}

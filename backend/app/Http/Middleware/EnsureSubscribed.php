<?php

namespace App\Http\Middleware;

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

        if (! $this->subscriptions->hasAccess($user)) {
            $trial = $this->subscriptions->trialStatus($user);

            return response()->json([
                'message' => $trial['expired']
                    ? 'Your free trial has ended. Subscribe to continue.'
                    : 'Subscription required',
                'code' => $trial['expired'] ? 'trial_expired' : 'subscription_required',
            ], 402);
        }

        return $next($request);
    }
}

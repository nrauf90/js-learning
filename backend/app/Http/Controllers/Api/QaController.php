<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class QaController extends Controller
{
    /** Local/testing only — backdate account so trial is expired (E2E). */
    public function expireTrial(Request $request): JsonResponse
    {
        $days = max(1, (int) config('billing.trial_days', 7));

        $request->user()->forceFill([
            'created_at' => now()->subDays($days + 1),
        ])->save();

        return response()->json(['ok' => true]);
    }

    /**
     * Local/testing only — the reset token that would have gone out by email.
     *
     * The E2E suite cannot read a mailbox, and MAIL_MAILER=log means scraping
     * laravel.log for a URL, which breaks the first time the log format or the
     * mail template changes. Minting the token the same way the mailer does
     * lets the browser walk the real reset page against the real endpoint.
     *
     * Every route on this controller is registered inside an
     * `app()->environment('local', 'testing')` guard in routes/api.php, so none
     * of this exists in production. Requiring the caller to already hold a
     * bearer token for *some* account is a second lock on the same door: even
     * if the guard were ever removed, this could not be used anonymously to
     * mint a reset for an arbitrary address.
     */
    public function passwordResetToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        $user = Password::broker()->getUser(['email' => $validated['email']]);

        if (! $user) {
            return response()->json(['message' => 'No such account.'], 404);
        }

        return response()->json([
            'token' => Password::broker()->createToken($user),
        ]);
    }
}

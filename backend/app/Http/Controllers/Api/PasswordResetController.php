<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * "I forgot my password."
 *
 * Until now the only way back into a locked-out account was for a shop admin
 * to retype a password in StaffController::update(), or for a platform operator
 * to do it by hand in the database — which meant somebody other than the
 * account holder chose, knew, and usually wrote down that password.
 *
 * Both endpoints are deliberately unauthenticated and deliberately vague. See
 * the notes on each for what they refuse to say and why.
 */
class PasswordResetController extends Controller
{
    /**
     * Send a reset link.
     *
     * The response is the same whether or not the address belongs to an
     * account. Anything else turns this into an account-enumeration oracle: a
     * script walking a list of addresses learns which shopkeepers bank here,
     * which is worth money to whoever is phishing them next.
     *
     * Laravel's own broker throttle (60s per address, config/auth.php) rides on
     * top of the route throttle, so one address cannot be mail-bombed even from
     * many source addresses.
     */
    public function forgot(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $status = Password::sendResetLink(['email' => $validated['email']]);

        // RESET_THROTTLED is folded into the success answer too: telling the
        // caller "you already asked for this one recently" confirms the address
        // exists just as loudly as a plain success would.
        if (! in_array($status, [Password::RESET_LINK_SENT, Password::INVALID_USER, Password::RESET_THROTTLED], true)) {
            return response()->json([
                'message' => 'The reset link could not be sent. Please try again in a moment.',
            ], 500);
        }

        return response()->json([
            'message' => 'If that email belongs to an account, a reset link is on its way. It expires in one hour.',
        ]);
    }

    /**
     * Consume a reset token and set the new password.
     *
     * Every other session dies with the old password. A reset is what someone
     * does when they suspect the old password is loose, so leaving the till
     * logged in on the device that leaked it would defeat the exercise.
     */
    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            [
                'email' => $validated['email'],
                'password' => $validated['password'],
                'password_confirmation' => $request->input('password_confirmation'),
                'token' => $validated['token'],
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Sanctum tokens, not sessions: this is a token API, and the
                // phone at the counter is holding one that is good for 30 days.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            // One message for a bad token, an expired token and a mismatched
            // address alike — distinguishing them would let someone probe which
            // addresses have a live reset outstanding.
            throw ValidationException::withMessages([
                'email' => ['This reset link is invalid or has expired. Please request a new one.'],
            ]);
        }

        return response()->json([
            'message' => 'Password updated. You can log in with your new password.',
        ]);
    }
}

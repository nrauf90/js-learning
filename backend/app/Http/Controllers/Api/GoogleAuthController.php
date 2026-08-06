<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

class GoogleAuthController extends Controller
{
    /** Cache key prefix for one-time Google sign-in exchange codes. */
    private const CODE_CACHE_PREFIX = 'google_auth_code:';

    public function redirect(): SymfonyRedirect
    {
        return Socialite::driver('google')
            ->stateless()
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        $frontend = rtrim(config('services.frontend.url', env('FRONTEND_URL', 'http://localhost:3000')), '/');

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (Throwable $e) {
            return redirect()->away($frontend.'/login.html?error=google_auth_failed');
        }

        $user = User::where('google_id', $googleUser->getId())->first();

        if (! $user && $googleUser->getEmail()) {
            $user = User::where('email', $googleUser->getEmail())->first();
        }

        if ($user) {
            $user->forceFill([
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar() ?: $user->avatar,
                'name' => $user->name ?: ($googleUser->getName() ?: 'Google User'),
            ])->save();
        } else {
            $user = User::create([
                'name' => $googleUser->getName() ?: 'Google User',
                'email' => $googleUser->getEmail() ?: $googleUser->getId().'@google.local',
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
                'password' => Hash::make(Str::random(32)),
            ]);
        }

        $token = $user->createToken('api')->plainTextToken;

        // Never put the bearer token itself in a URL — it would end up in
        // browser history, Referer headers, and server access logs. Hand
        // back a short-lived, single-use code instead; the frontend
        // exchanges it for the real token via POST (see exchange()).
        $code = Str::random(40);
        Cache::put(self::CODE_CACHE_PREFIX.$code, [
            'token' => $token,
            'user_id' => $user->id,
        ], now()->addMinutes(2));

        return redirect()->away($frontend.'/login.html?google_code='.$code);
    }

    public function exchange(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $payload = Cache::pull(self::CODE_CACHE_PREFIX.$validated['code']);

        if (! $payload) {
            throw ValidationException::withMessages([
                'code' => ['This sign-in link has expired. Please try again.'],
            ]);
        }

        $user = User::find($payload['user_id']);
        if (! $user) {
            throw ValidationException::withMessages([
                'code' => ['This sign-in link is no longer valid.'],
            ]);
        }

        return response()->json([
            'user' => $user->toAuthArray(),
            'token' => $payload['token'],
        ]);
    }
}

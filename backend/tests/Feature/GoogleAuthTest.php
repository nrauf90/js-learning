<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    // M12-T2 regression: the Google OAuth callback used to redirect with the
    // Sanctum bearer token directly in the URL (?token=...), which lands in
    // browser history, Referer headers, and server access logs. It now hands
    // back a short-lived, single-use code that must be exchanged via POST.

    public function test_exchange_returns_user_and_token_for_a_valid_code(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        Cache::put('google_auth_code:test-code-1', ['token' => $token, 'user_id' => $user->id], now()->addMinutes(2));

        $this->postJson('/api/auth/google/exchange', ['code' => 'test-code-1'])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('token', $token);
    }

    public function test_exchange_code_is_single_use(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;
        Cache::put('google_auth_code:test-code-2', ['token' => $token, 'user_id' => $user->id], now()->addMinutes(2));

        $this->postJson('/api/auth/google/exchange', ['code' => 'test-code-2'])->assertOk();

        $this->postJson('/api/auth/google/exchange', ['code' => 'test-code-2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_exchange_rejects_unknown_or_expired_code(): void
    {
        $this->postJson('/api/auth/google/exchange', ['code' => 'never-issued'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }
}

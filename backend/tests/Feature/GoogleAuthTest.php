<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
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

    /* ------------------------------------------------- callback: email trust */

    /**
     * Signing in with Google adopts an existing account when the addresses
     * match. That is the expected behaviour — but only because Google is
     * asserting it verified the address. Without the claim, the "address" is a
     * string somebody typed into a throwaway Google profile, and honouring it
     * would hand them a shop's takings without them ever knowing the password.
     */
    public function test_an_unverified_google_email_cannot_adopt_an_existing_account(): void
    {
        $victim = User::factory()->create([
            'email' => 'shopkeeper@example.com',
            'password' => 'the-real-password-1',
        ]);

        $this->mockGoogleUser('9999', 'shopkeeper@example.com', emailVerified: false);

        $this->get('/api/auth/google/callback')
            ->assertRedirectContains('error=google_email_unverified');

        // Untouched: no google_id grafted on, and no token minted.
        $this->assertNull($victim->fresh()->google_id);
        $this->assertSame(0, $victim->fresh()->tokens()->count());
    }

    public function test_a_verified_google_email_adopts_the_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'shopkeeper@example.com']);

        $this->mockGoogleUser('9999', 'shopkeeper@example.com', emailVerified: true);

        $this->get('/api/auth/google/callback')
            ->assertRedirectContains('google_code=');

        $this->assertSame('9999', $user->fresh()->google_id);
    }

    /**
     * A fresh account on an unverified address would claim that address, and
     * the person who actually owns it would then be turned away by the check
     * above — locked out by someone else's typo.
     */
    public function test_an_unverified_google_email_cannot_create_a_new_account(): void
    {
        $this->mockGoogleUser('12345', 'nobody@example.com', emailVerified: false);

        $this->get('/api/auth/google/callback')
            ->assertRedirectContains('error=google_email_unverified');

        $this->assertDatabaseMissing('users', ['email' => 'nobody@example.com']);
    }

    public function test_a_verified_google_email_creates_a_new_account(): void
    {
        $this->mockGoogleUser('12345', 'newshop@example.com', emailVerified: true);

        $this->get('/api/auth/google/callback')
            ->assertRedirectContains('google_code=');

        $this->assertDatabaseHas('users', ['email' => 'newshop@example.com', 'google_id' => '12345']);
    }

    /**
     * Google sends the claim as a real boolean on the id_token and as the
     * string "true" from the userinfo endpoint. Both have to count, or the
     * gate would reject every sign-in that came the second way.
     */
    public function test_the_string_form_of_the_verified_claim_is_accepted(): void
    {
        $this->mockGoogleUser('777', 'stringclaim@example.com', emailVerified: 'true');

        $this->get('/api/auth/google/callback')
            ->assertRedirectContains('google_code=');

        $this->assertDatabaseHas('users', ['email' => 'stringclaim@example.com']);
    }

    /**
     * Builds the Socialite user the controller will see. `->user` is the raw
     * OIDC payload Socialite keeps alongside the normalised fields, and it is
     * where the email_verified claim lives.
     */
    private function mockGoogleUser(string $id, string $email, bool|string $emailVerified): void
    {
        $googleUser = new SocialiteUser;
        $googleUser->map([
            'id' => $id,
            'name' => 'Test Shopkeeper',
            'email' => $email,
            'avatar' => null,
        ]);
        $googleUser->user = [
            'sub' => $id,
            'email' => $email,
            'email_verified' => $emailVerified,
        ];

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($googleUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reset_link_is_mailed_to_a_known_address(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'shopkeeper@example.com']);

        $this->postJson('/api/password/forgot', ['email' => 'shopkeeper@example.com'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_link_points_at_the_frontend_reset_page(): void
    {
        Notification::fake();
        config(['services.frontend.url' => 'https://app.example.test']);
        $user = User::factory()->create(['email' => 'shopkeeper@example.com']);

        $this->postJson('/api/password/forgot', ['email' => 'shopkeeper@example.com'])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            // Fragment, not query: it is never sent to a server, so the token
            // stays out of the frontend host's logs — and it survives a static
            // host that rewrites /page.html to /page and drops the query.
            return str_starts_with($url, 'https://app.example.test/reset-password.html#token=')
                && str_contains($url, 'email=shopkeeper%40example.com');
        });
    }

    /**
     * The whole point of the neutral response: a script walking a list of
     * addresses must not be able to tell which ones bank here.
     */
    public function test_an_unknown_address_gets_the_same_answer_as_a_known_one(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'known@example.com']);

        $known = $this->postJson('/api/password/forgot', ['email' => 'known@example.com'])->assertOk();
        $unknown = $this->postJson('/api/password/forgot', ['email' => 'nobody@example.com'])->assertOk();

        $this->assertSame($known->json('message'), $unknown->json('message'));
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_a_valid_token_sets_the_new_password(): void
    {
        $user = User::factory()->create([
            'email' => 'shopkeeper@example.com',
            'password' => 'old-password-123',
        ]);

        $token = Password::broker()->createToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => 'shopkeeper@example.com',
            'password' => 'brand-new-password-9',
            'password_confirmation' => 'brand-new-password-9',
        ])->assertOk();

        $this->assertTrue(Hash::check('brand-new-password-9', $user->fresh()->password));

        $this->postJson('/api/login', [
            'email' => 'shopkeeper@example.com',
            'password' => 'brand-new-password-9',
        ])->assertOk();
    }

    /**
     * A reset is what someone does when they think the old password is loose.
     * Leaving the till logged in on the device that leaked it would defeat it.
     */
    public function test_resetting_revokes_every_existing_token(): void
    {
        $user = User::factory()->create(['email' => 'shopkeeper@example.com']);
        $stale = $user->createToken('api')->plainTextToken;
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => 'shopkeeper@example.com',
            'password' => 'brand-new-password-9',
            'password_confirmation' => 'brand-new-password-9',
        ])->assertOk();

        $this->assertSame(0, $user->fresh()->tokens()->count());

        $this->withHeader('Authorization', 'Bearer '.$stale)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_the_token_cannot_be_used_twice(): void
    {
        $user = User::factory()->create(['email' => 'shopkeeper@example.com']);
        $token = Password::broker()->createToken($user);

        $payload = [
            'token' => $token,
            'email' => 'shopkeeper@example.com',
            'password' => 'brand-new-password-9',
            'password_confirmation' => 'brand-new-password-9',
        ];

        $this->postJson('/api/password/reset', $payload)->assertOk();

        $this->postJson('/api/password/reset', [
            ...$payload,
            'password' => 'another-password-77',
            'password_confirmation' => 'another-password-77',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_a_forged_token_is_refused(): void
    {
        User::factory()->create(['email' => 'shopkeeper@example.com']);

        $this->postJson('/api/password/reset', [
            'token' => 'not-a-real-token',
            'email' => 'shopkeeper@example.com',
            'password' => 'brand-new-password-9',
            'password_confirmation' => 'brand-new-password-9',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    /**
     * A token issued for one account must not move sideways onto another.
     */
    public function test_a_token_issued_for_one_account_cannot_reset_a_different_one(): void
    {
        $victim = User::factory()->create(['email' => 'victim@example.com', 'password' => 'victim-password-1']);
        $attacker = User::factory()->create(['email' => 'attacker@example.com']);

        $token = Password::broker()->createToken($attacker);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => 'victim@example.com',
            'password' => 'taken-over-password-1',
            'password_confirmation' => 'taken-over-password-1',
        ])->assertUnprocessable();

        $this->assertTrue(Hash::check('victim-password-1', $victim->fresh()->password));
    }

    public function test_a_weak_or_unconfirmed_password_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'shopkeeper@example.com']);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => 'shopkeeper@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->postJson('/api/password/reset', [
            'token' => $token,
            'email' => 'shopkeeper@example.com',
            'password' => 'long-enough-password',
            'password_confirmation' => 'a-different-password',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_forgot_requires_a_well_formed_address(): void
    {
        $this->postJson('/api/password/forgot', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }
}

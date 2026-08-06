<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/profile', ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('user.name', 'New Name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
    }

    public function test_password_update_requires_correct_current_password(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_user_can_update_password(): void
    {
        $user = User::factory()->create(['password' => 'password123']);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/user/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('new-password-456', $user->fresh()->password));

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'new-password-456',
        ])->assertOk();
    }

    public function test_guest_cannot_update_profile(): void
    {
        $this->putJson('/api/user/profile', ['name' => 'X'])->assertUnauthorized();
        $this->putJson('/api/user/password', [])->assertUnauthorized();
    }

    public function test_password_update_revokes_other_sessions(): void
    {
        // M12-T4: changing the password should log out every other
        // device/session — only the token used to make this request survives.
        $user = User::factory()->create(['password' => 'password123']);
        $currentToken = $user->createToken('current')->plainTextToken;
        $otherToken = $user->createToken('other-device')->plainTextToken;

        $this->withToken($currentToken)
            ->putJson('/api/user/password', [
                'current_password' => 'password123',
                'password' => 'new-password-456',
                'password_confirmation' => 'new-password-456',
            ])
            ->assertOk();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->withToken($otherToken)
            ->getJson('/api/user')
            ->assertUnauthorized();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->withToken($currentToken)
            ->getJson('/api/user')
            ->assertOk();
    }
}

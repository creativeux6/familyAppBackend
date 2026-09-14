<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class ChangePasswordTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_authenticated_user_can_change_password(): void
    {
        $user = $this->createUserWithFamily([
            'phone' => '+923003333333',
            'password' => 'old-password',
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Password updated.');

        $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => 'new-password',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => 'old-password',
        ])->assertStatus(422);
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = $this->createUserWithFamily([
            'phone' => '+923004444444',
            'password' => 'old-password',
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_change_password_revokes_other_tokens(): void
    {
        $user = $this->createUserWithFamily([
            'phone' => '+923005555555',
            'password' => 'old-password',
        ]);

        $current = $user->createToken('mobile')->plainTextToken;
        $other = $user->createToken('other')->plainTextToken;
        $otherId = PersonalAccessToken::findToken($other)?->id;

        $this->withToken($current)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'old-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $otherId,
        ]);

        $this->withToken($current)->getJson('/api/v1/auth/me')->assertOk();
    }
}

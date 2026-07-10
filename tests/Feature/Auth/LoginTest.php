<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_user_can_login_with_phone_and_password(): void
    {
        $user = $this->createUserWithFamily([
            'phone' => '+923001111111',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('action', 'logged_in')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['access_token', 'user']);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'phone' => '+923002222222',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422);
    }
}

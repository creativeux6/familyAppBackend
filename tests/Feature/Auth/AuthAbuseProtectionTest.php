<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class AuthAbuseProtectionTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('login-ip:127.0.0.1');
        RateLimiter::clear('login-ip-hour:127.0.0.1');
        RateLimiter::clear('register-ip:127.0.0.1');
        RateLimiter::clear('register-ip-hour:127.0.0.1');
    }

    public function test_login_is_rate_limited_by_ip(): void
    {
        $user = $this->createUserWithFamily([
            'phone' => '+923003333333',
            'password' => 'password',
        ]);

        $phoneKey = 'login-phone:'.hash('sha256', $user->phone);
        RateLimiter::clear($phoneKey);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'phone' => $user->phone,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('message', 'Too many attempts. Please wait and try again.');
    }

    public function test_register_is_rate_limited_by_ip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/auth/register', [
                'phone' => '+92300444444'.$i,
                'password' => 'password1',
                'password_confirmation' => 'password1',
                'display_name' => 'User '.$i,
            ]);
        }

        $response = $this->postJson('/api/v1/auth/register', [
            'phone' => '+923004444449',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'display_name' => 'User Extra',
        ]);

        $response->assertStatus(429);
    }

    public function test_register_does_not_reveal_existing_phone(): void
    {
        User::factory()->create([
            'phone' => '+923005555555',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'phone' => '+923005555555',
            'password' => 'password1',
            'password_confirmation' => 'password1',
            'display_name' => 'Duplicate',
        ]);

        $response->assertStatus(422)
            ->assertJsonMissing(['Phone number is already registered.'])
            ->assertJsonPath(
                'errors.phone.0',
                'Unable to complete registration with these details.',
            );
    }
}

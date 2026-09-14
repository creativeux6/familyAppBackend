<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class SessionPersistenceTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_me_keeps_existing_token_valid(): void
    {
        $user = $this->createUserWithFamily([
            'phone' => '+923006666666',
            'password' => 'password',
        ]);

        $token = $user->createToken('mobile')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.phone', $user->phone);

        $this->forgetAuthGuards();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    public function test_refresh_rotates_only_current_token(): void
    {
        $user = $this->createUserWithFamily([
            'phone' => '+923007777777',
            'password' => 'password',
        ]);

        $current = $user->createToken('mobile')->plainTextToken;
        $other = $user->createToken('other-device')->plainTextToken;
        $otherId = PersonalAccessToken::findToken($other)?->id;

        $response = $this->withToken($current)->postJson('/api/v1/auth/refresh');
        $response->assertOk()->assertJsonStructure(['access_token', 'user']);

        $newToken = $response->json('access_token');
        $this->assertNotSame($current, $newToken);
        $this->assertNull(PersonalAccessToken::findToken($current));

        $this->forgetAuthGuards();

        $this->withToken($current)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($newToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherId]);
    }

    public function test_logout_revokes_only_current_token(): void
    {
        $user = $this->createUserWithFamily([
            'phone' => '+923008888888',
            'password' => 'password',
        ]);

        $current = $user->createToken('mobile')->plainTextToken;
        $other = $user->createToken('other-device')->plainTextToken;

        $this->withToken($current)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertNull(PersonalAccessToken::findToken($current));

        $this->forgetAuthGuards();

        $this->withToken($current)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withToken($other)->getJson('/api/v1/auth/me')->assertOk();
    }

    /**
     * Sanctum checks the web guard before bearer tokens. Feature tests keep the
     * resolved user across requests, which would make revoked tokens look valid.
     */
    private function forgetAuthGuards(): void
    {
        $this->app['auth']->forgetGuards();
    }
}

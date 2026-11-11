<?php

namespace Tests\Feature\Encryption;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class IdentityVaultTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_user_can_store_and_reveal_identity_vault(): void
    {
        $user = $this->actingAsUser($this->createUserWithFamily());
        $privateKey = base64_encode(random_bytes(32));

        $this->putJson('/api/v1/encryption/identity-vault', [
            'private_identity_key' => $privateKey,
            'encryption_version' => 1,
        ])->assertCreated()
            ->assertJsonPath('message', 'Identity vault stored.');

        $this->getJson('/api/v1/encryption/identity-vault')
            ->assertOk()
            ->assertJsonPath('private_identity_key', $privateKey)
            ->assertJsonPath('encryption_version', 1);
    }

    public function test_reveal_returns_not_found_when_vault_missing(): void
    {
        $this->actingAsUser($this->createUserWithFamily());

        $this->getJson('/api/v1/encryption/identity-vault')
            ->assertNotFound()
            ->assertJsonPath('message', 'No identity vault found.');
    }

    public function test_user_cannot_reveal_another_users_vault(): void
    {
        $owner = $this->createUserWithFamily();
        $other = $this->createUserWithFamily();
        $privateKey = base64_encode(random_bytes(32));

        $this->actingAsUser($owner);
        $this->putJson('/api/v1/encryption/identity-vault', [
            'private_identity_key' => $privateKey,
        ])->assertCreated();

        $this->actingAsUser($other);
        $this->getJson('/api/v1/encryption/identity-vault')
            ->assertNotFound();
    }
}

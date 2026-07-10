<?php

namespace Tests\Feature\Encryption;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class KeyBackupTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_user_can_store_and_fetch_key_backup(): void
    {
        $user = $this->actingAsUser($this->createUserWithFamily());

        $store = $this->postJson('/api/v1/encryption/key-backup', [
            'encrypted_private_key_blob' => base64_encode('encrypted-blob'),
            'salt' => base64_encode('random-salt'),
            'encryption_version' => 1,
        ]);

        $store->assertCreated()
            ->assertJsonPath('message', 'Key backup stored.');

        $fetch = $this->getJson('/api/v1/encryption/key-backup');

        $fetch->assertOk()
            ->assertJsonPath('encryption_version', 1)
            ->assertJsonStructure([
                'encrypted_private_key_blob',
                'salt',
                'encryption_version',
                'created_at',
            ]);
    }

    public function test_fetch_returns_not_found_when_no_backup_exists(): void
    {
        $this->actingAsUser($this->createUserWithFamily());

        $this->getJson('/api/v1/encryption/key-backup')
            ->assertNotFound()
            ->assertJsonPath('message', 'No key backup found.');
    }

    public function test_storing_backup_replaces_previous_active_backup(): void
    {
        $this->actingAsUser($this->createUserWithFamily());

        $this->postJson('/api/v1/encryption/key-backup', [
            'encrypted_private_key_blob' => base64_encode('first-blob'),
            'salt' => base64_encode('first-salt'),
        ])->assertCreated();

        $this->postJson('/api/v1/encryption/key-backup', [
            'encrypted_private_key_blob' => base64_encode('second-blob'),
            'salt' => base64_encode('second-salt'),
        ])->assertCreated();

        $this->getJson('/api/v1/encryption/key-backup')
            ->assertOk()
            ->assertJsonPath(
                'encrypted_private_key_blob',
                base64_encode('second-blob'),
            );
    }
}

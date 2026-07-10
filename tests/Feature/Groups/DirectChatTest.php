<?php

namespace Tests\Feature\Groups;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class DirectChatTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_connected_users_can_open_direct_chat(): void
    {
        $alice = $this->actingAsUser($this->createUserWithFamily([
            'display_name' => 'Alice',
        ]));
        $bob = $this->createUserWithFamily([
            'display_name' => 'Bob',
        ]);

        $this->connectUsers($alice, $bob);

        $create = $this->postJson('/api/v1/groups/direct', [
            'user_uuid' => $bob->uuid,
        ]);

        $create->assertOk()
            ->assertJsonPath('type', 'direct')
            ->assertJsonPath('display_name', 'Bob');

        $groupUuid = $create->json('uuid');

        $again = $this->postJson('/api/v1/groups/direct', [
            'user_uuid' => $bob->uuid,
        ]);

        $again->assertOk()
            ->assertJsonPath('uuid', $groupUuid);
    }

    public function test_direct_chat_requires_connection(): void
    {
        $alice = $this->actingAsUser($this->createUserWithFamily());
        $stranger = $this->createUserWithFamily();

        $this->postJson('/api/v1/groups/direct', [
            'user_uuid' => $stranger->uuid,
        ])->assertStatus(422);
    }
}

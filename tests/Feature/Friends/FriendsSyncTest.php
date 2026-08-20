<?php

namespace Tests\Feature\Friends;

use App\Models\Connection;
use App\Support\PhoneHash;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class FriendsSyncTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_sync_returns_registered_contact_matches(): void
    {
        $alice = $this->actingAsUser($this->createUserWithFamily([
            'display_name' => 'Alice',
            'phone' => '+923001111111',
        ]));
        $bob = $this->createUserWithFamily([
            'display_name' => 'Bob',
            'phone' => '+923002222222',
        ]);
        $this->createUserWithFamily([
            'display_name' => 'Carol',
            'phone' => '+923003333333',
        ]);

        $response = $this->postJson('/api/v1/friends/sync', [
            'phone_hashes' => [PhoneHash::hash($bob->phone)],
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.user_uuid', $bob->uuid)
            ->assertJsonPath('matches.0.is_registered', true)
            ->assertJsonPath('matches.0.is_connected_family', false)
            ->assertJsonPath('matches.0.kinship_label', null)
            ->assertJsonPath('matches.0.phone_hash', PhoneHash::hash($bob->phone));
    }

    public function test_sync_hides_anonymous_and_blocked_users(): void
    {
        $alice = $this->actingAsUser($this->createUserWithFamily([
            'phone' => '+923001111111',
        ]));
        $ghost = $this->createUserWithFamily([
            'phone' => '+923004444444',
            'is_anonymous' => true,
        ]);
        $blocked = $this->createUserWithFamily([
            'phone' => '+923005555555',
        ]);

        Connection::query()->create([
            'uuid' => (string) Str::uuid(),
            'requester_user_id' => $alice->id,
            'recipient_user_id' => $blocked->id,
            'status' => 'blocked',
        ]);

        $this->postJson('/api/v1/friends/sync', [
            'phone_hashes' => [
                PhoneHash::hash($ghost->phone),
                PhoneHash::hash($blocked->phone),
            ],
        ])->assertOk()->assertJsonCount(0, 'matches');
    }

    public function test_registered_contact_can_open_direct_chat_without_family_connection(): void
    {
        $alice = $this->actingAsUser($this->createUserWithFamily([
            'display_name' => 'Alice',
            'phone' => '+923001111111',
        ]));
        $bob = $this->createUserWithFamily([
            'display_name' => 'Bob',
            'phone' => '+923002222222',
        ]);

        $this->postJson('/api/v1/friends/sync', [
            'phone_hashes' => [PhoneHash::hash($bob->phone)],
        ])->assertOk();

        $this->postJson('/api/v1/groups/direct', [
            'user_uuid' => $bob->uuid,
        ])->assertOk()->assertJsonPath('type', 'direct');
    }

    public function test_direct_chat_still_requires_contact_or_connection(): void
    {
        $this->actingAsUser($this->createUserWithFamily());
        $stranger = $this->createUserWithFamily();

        $this->postJson('/api/v1/groups/direct', [
            'user_uuid' => $stranger->uuid,
        ])->assertStatus(422);
    }
}

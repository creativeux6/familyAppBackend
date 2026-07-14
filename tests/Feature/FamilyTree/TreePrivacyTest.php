<?php

namespace Tests\Feature\FamilyTree;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class TreePrivacyTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_unconnected_registered_member_appears_as_unregistered(): void
    {
        $pair = $this->createFamilyPair();
        $this->actingAsUser($pair['users']['viewer']);

        $response = $this->getJson('/api/v1/family-tree');

        $response->assertOk();

        $otherNode = collect($response->json('members'))
            ->firstWhere('uuid', $pair['members']['other']->uuid);

        $this->assertNotNull($otherNode);
        $this->assertFalse($otherNode['is_ghost']);
        $this->assertFalse($otherNode['is_registered']);
        $this->assertNull($otherNode['user_uuid']);
        $this->assertSame('Other', $otherNode['first_name']);
        $this->assertSame('User', $otherNode['last_name']);
    }

    public function test_connected_member_shows_full_profile_in_tree(): void
    {
        $pair = $this->createFamilyPair();
        $this->connectUsers($pair['users']['viewer'], $pair['users']['other']);
        $this->actingAsUser($pair['users']['viewer']);

        $response = $this->getJson('/api/v1/family-tree');

        $response->assertOk();

        $otherNode = collect($response->json('members'))
            ->firstWhere('uuid', $pair['members']['other']->uuid);

        $this->assertNotNull($otherNode);
        $this->assertFalse($otherNode['is_ghost'] ?? false);
        $this->assertTrue($otherNode['is_registered']);
        $this->assertSame('Other', $otherNode['first_name']);
        $this->assertSame('User', $otherNode['last_name']);
    }

    public function test_disconnect_masks_member_as_unregistered_for_viewer(): void
    {
        $pair = $this->createFamilyPair();
        $connection = $this->connectUsers($pair['users']['viewer'], $pair['users']['other']);
        $this->actingAsUser($pair['users']['viewer']);

        $this->postJson("/api/v1/connections/{$connection->uuid}/disconnect")
            ->assertOk();

        $response = $this->getJson('/api/v1/family-tree');

        $response->assertOk();

        $otherNode = collect($response->json('members'))
            ->firstWhere('uuid', $pair['members']['other']->uuid);

        $this->assertNotNull($otherNode);
        $this->assertFalse($otherNode['is_ghost']);
        $this->assertFalse($otherNode['is_registered']);
        $this->assertNull($otherNode['user_uuid']);
        $this->assertSame('Other', $otherNode['first_name']);
    }
}

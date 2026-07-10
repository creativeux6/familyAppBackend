<?php

namespace Tests\Concerns;

use App\Models\Connection;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
use App\Models\RelationshipEdgeType;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

trait CreatesTestUsers
{
    protected function createUserWithFamily(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);

        $family = Family::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test Family',
            'member_count' => 1,
        ]);

        FamilyMember::query()->create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $family->uuid,
            'user_id' => $user->id,
            'first_name' => 'Test',
            'last_name' => 'User',
            'gender' => 'unknown',
        ]);

        return $user->fresh();
    }

    protected function connectUsers(User $a, User $b): Connection
    {
        return Connection::query()->create([
            'uuid' => (string) Str::uuid(),
            'requester_user_id' => $a->id,
            'recipient_user_id' => $b->id,
            'status' => 'connected',
            'connected_at' => now(),
        ]);
    }

    protected function actingAsUser(User $user): User
    {
        Sanctum::actingAs($user);

        return $user;
    }

    /** @return array{family: Family, users: array{viewer: User, other: User}, members: array{viewer: FamilyMember, other: FamilyMember}} */
    protected function createFamilyPair(): array
    {
        $family = Family::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Shared Family',
            'member_count' => 2,
        ]);

        $viewer = User::factory()->create(['display_name' => 'Viewer User']);
        $other = User::factory()->create(['display_name' => 'Other User']);

        $viewerMember = FamilyMember::query()->create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $family->uuid,
            'user_id' => $viewer->id,
            'first_name' => 'Viewer',
            'last_name' => 'User',
            'gender' => 'male',
        ]);

        $otherMember = FamilyMember::query()->create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $family->uuid,
            'user_id' => $other->id,
            'first_name' => 'Other',
            'last_name' => 'User',
            'gender' => 'female',
        ]);

        $parentType = RelationshipEdgeType::query()->where('code', 'parent_of')->firstOrFail();

        RelationshipEdge::query()->create([
            'uuid' => (string) Str::uuid(),
            'from_member_uuid' => $viewerMember->uuid,
            'to_member_uuid' => $otherMember->uuid,
            'edge_type_id' => $parentType->id,
        ]);

        return [
            'family' => $family,
            'users' => ['viewer' => $viewer, 'other' => $other],
            'members' => ['viewer' => $viewerMember, 'other' => $otherMember],
        ];
    }
}

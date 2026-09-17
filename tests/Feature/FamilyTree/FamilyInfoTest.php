<?php

namespace Tests\Feature\FamilyTree;

use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class FamilyInfoTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_user_can_update_mother_in_family_info(): void
    {
        $this->actingAsUser($this->createUserWithFamily());

        $response = $this->patchJson('/api/v1/family-tree/family-info', [
            'mother' => [
                'first_name' => 'Fatima',
                'last_name' => 'Khan',
                'gender' => 'female',
                'is_living' => true,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('family_info.mother.first_name', 'Fatima')
            ->assertJsonPath('family_info.mother.last_name', 'Khan');
    }

    public function test_family_info_requires_authentication(): void
    {
        $this->getJson('/api/v1/family-tree/family-info')
            ->assertUnauthorized();
    }

    public function test_user_can_save_multiple_spouses_with_per_child_other_parent(): void
    {
        $user = $this->createUserWithFamily(['display_name' => 'Ahmed']);
        $self = FamilyMember::query()->where('user_id', $user->id)->firstOrFail();
        $self->update(['first_name' => 'Ahmed', 'last_name' => 'Ali', 'gender' => 'male']);

        $this->actingAsUser($user);

        $response = $this->patchJson('/api/v1/family-tree/family-info', [
            'has_multiple_partners' => true,
            'spouses' => [
                [
                    'first_name' => 'Sara',
                    'last_name' => 'Ali',
                    'gender' => 'female',
                    'is_living' => true,
                    'father' => [
                        'first_name' => 'Omar',
                        'last_name' => 'Hassan',
                        'gender' => 'male',
                    ],
                ],
                [
                    'first_name' => 'Layla',
                    'last_name' => 'Ali',
                    'gender' => 'female',
                    'is_living' => true,
                ],
            ],
            'children' => [],
        ]);

        $response->assertOk()
            ->assertJsonPath('family_info.has_multiple_partners', true)
            ->assertJsonCount(2, 'family_info.spouses')
            ->assertJsonPath('family_info.spouses.0.first_name', 'Sara')
            ->assertJsonPath('family_info.spouses.1.first_name', 'Layla')
            ->assertJsonPath('family_info.spouses.0.father.first_name', 'Omar');

        $saraUuid = $response->json('family_info.spouses.0.uuid');
        $laylaUuid = $response->json('family_info.spouses.1.uuid');

        $childResponse = $this->patchJson('/api/v1/family-tree/family-info', [
            'has_multiple_partners' => true,
            'spouses' => $response->json('family_info.spouses'),
            'children' => [
                [
                    'first_name' => 'Yusuf',
                    'last_name' => 'Ali',
                    'gender' => 'male',
                    'other_parent_uuid' => $saraUuid,
                ],
                [
                    'first_name' => 'Noor',
                    'last_name' => 'Ali',
                    'gender' => 'female',
                    'other_parent_uuid' => $laylaUuid,
                ],
            ],
        ]);

        $childResponse->assertOk()->assertJsonCount(2, 'family_info.children');

        $childrenByName = collect($childResponse->json('family_info.children'))
            ->keyBy('first_name');

        $this->assertSame($saraUuid, $childrenByName['Yusuf']['other_parent_uuid']);
        $this->assertSame($laylaUuid, $childrenByName['Noor']['other_parent_uuid']);

        $yusufUuid = $childrenByName['Yusuf']['uuid'];
        $noorUuid = $childrenByName['Noor']['uuid'];

        $this->assertTrue($this->hasParentEdge($self->uuid, $yusufUuid));
        $this->assertTrue($this->hasParentEdge($saraUuid, $yusufUuid));
        $this->assertFalse($this->hasParentEdge($laylaUuid, $yusufUuid));

        $this->assertTrue($this->hasParentEdge($self->uuid, $noorUuid));
        $this->assertTrue($this->hasParentEdge($laylaUuid, $noorUuid));
        $this->assertFalse($this->hasParentEdge($saraUuid, $noorUuid));

        $spouseEdges = RelationshipEdge::query()
            ->where(function ($query) use ($self) {
                $query->where('from_member_uuid', $self->uuid)
                    ->orWhere('to_member_uuid', $self->uuid);
            })
            ->whereHas('edgeType', fn ($q) => $q->where('code', 'spouse_of'))
            ->count();

        $this->assertSame(2, $spouseEdges);
    }

    public function test_multiple_partners_require_other_parent_on_children(): void
    {
        $user = $this->createUserWithFamily();
        FamilyMember::query()->where('user_id', $user->id)->update(['gender' => 'male']);
        $this->actingAsUser($user);

        $setup = $this->patchJson('/api/v1/family-tree/family-info', [
            'spouses' => [
                ['first_name' => 'Sara', 'last_name' => 'Ali', 'gender' => 'female'],
                ['first_name' => 'Layla', 'last_name' => 'Ali', 'gender' => 'female'],
            ],
        ]);
        $setup->assertOk();

        $this->patchJson('/api/v1/family-tree/family-info', [
            'spouses' => $setup->json('family_info.spouses'),
            'children' => [
                [
                    'first_name' => 'Yusuf',
                    'last_name' => 'Ali',
                    'gender' => 'male',
                ],
            ],
        ])->assertStatus(422);
    }

    public function test_single_spouse_still_auto_links_children(): void
    {
        $user = $this->createUserWithFamily();
        $self = FamilyMember::query()->where('user_id', $user->id)->firstOrFail();
        $self->update(['gender' => 'male']);
        $this->actingAsUser($user);

        $response = $this->patchJson('/api/v1/family-tree/family-info', [
            'spouse' => [
                'first_name' => 'Sara',
                'last_name' => 'Ali',
                'gender' => 'female',
            ],
            'children' => [
                [
                    'first_name' => 'Yusuf',
                    'last_name' => 'Ali',
                    'gender' => 'male',
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('family_info.has_multiple_partners', false)
            ->assertJsonCount(1, 'family_info.spouses');

        $spouseUuid = $response->json('family_info.spouses.0.uuid');
        $childUuid = $response->json('family_info.children.0.uuid');

        $this->assertTrue($this->hasParentEdge($self->uuid, $childUuid));
        $this->assertTrue($this->hasParentEdge($spouseUuid, $childUuid));
        $this->assertSame($spouseUuid, $response->json('family_info.children.0.other_parent_uuid'));
    }

    private function hasParentEdge(string $parentUuid, string $childUuid): bool
    {
        return RelationshipEdge::query()
            ->where('from_member_uuid', $parentUuid)
            ->where('to_member_uuid', $childUuid)
            ->whereHas('edgeType', fn ($q) => $q->where('code', 'parent_of'))
            ->exists();
    }
}

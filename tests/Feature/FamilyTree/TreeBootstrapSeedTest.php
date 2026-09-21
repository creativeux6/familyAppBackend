<?php

namespace Tests\Feature\FamilyTree;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
use App\Models\RelationshipEdgeType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestUsers;
use Tests\TestCase;

class TreeBootstrapSeedTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_male_viewer_bootstrap_is_father_side_first_without_children(): void
    {
        $tree = $this->seedExtendedFamily(viewerGender: 'male');
        $this->actingAsUser($tree['viewer']);

        $response = $this->getJson('/api/v1/family-tree?scope=bootstrap');
        $response->assertOk();

        $uuids = collect($response->json('members'))->pluck('uuid')->all();

        $this->assertContains($tree['self']->uuid, $uuids);
        $this->assertContains($tree['father']->uuid, $uuids);
        $this->assertContains($tree['mother']->uuid, $uuids);
        $this->assertContains($tree['paternal_gf']->uuid, $uuids);
        $this->assertContains($tree['paternal_gm']->uuid, $uuids);
        $this->assertContains($tree['uncle']->uuid, $uuids);
        $this->assertContains($tree['wife']->uuid, $uuids);
        $this->assertNotContains($tree['child']->uuid, $uuids);
        $this->assertNotContains($tree['maternal_gf']->uuid, $uuids);
        $this->assertNotContains($tree['maternal_gm']->uuid, $uuids);
        $this->assertNotContains($tree['maternal_uncle']->uuid, $uuids);
        $this->assertNotContains($tree['fil']->uuid, $uuids);

        $wifeNode = collect($response->json('members'))
            ->firstWhere('uuid', $tree['wife']->uuid);
        $this->assertNotNull($wifeNode);
        $this->assertGreaterThan(0, $wifeNode['expand_count']);

        $motherNode = collect($response->json('members'))
            ->firstWhere('uuid', $tree['mother']->uuid);
        $this->assertNotNull($motherNode);
        $this->assertGreaterThan(0, $motherNode['expand_count']);
    }

    public function test_female_viewer_bootstrap_includes_children(): void
    {
        $tree = $this->seedExtendedFamily(viewerGender: 'female');
        $this->actingAsUser($tree['viewer']);

        $response = $this->getJson('/api/v1/family-tree?scope=bootstrap');
        $response->assertOk();

        $uuids = collect($response->json('members'))->pluck('uuid')->all();
        $this->assertContains($tree['child']->uuid, $uuids);
        $this->assertContains($tree['wife']->uuid, $uuids); // husband in this case
    }

    public function test_expand_mother_returns_maternal_side_one_hop(): void
    {
        $tree = $this->seedExtendedFamily(viewerGender: 'male');
        $this->actingAsUser($tree['viewer']);

        $response = $this->getJson(
            '/api/v1/family-tree/members/'.$tree['mother']->uuid.'/neighborhood'
        );
        $response->assertOk();

        $uuids = collect($response->json('members'))->pluck('uuid')->all();
        $this->assertContains($tree['maternal_gf']->uuid, $uuids);
        $this->assertContains($tree['maternal_gm']->uuid, $uuids);
        $this->assertContains($tree['maternal_uncle']->uuid, $uuids);
        $this->assertNotContains($tree['child']->uuid, $uuids);
    }

    public function test_expand_wife_returns_children_parents_siblings(): void
    {
        $tree = $this->seedExtendedFamily(viewerGender: 'male');
        $this->actingAsUser($tree['viewer']);

        $response = $this->getJson(
            '/api/v1/family-tree/members/'.$tree['wife']->uuid.'/neighborhood'
        );
        $response->assertOk();

        $uuids = collect($response->json('members'))->pluck('uuid')->all();
        $this->assertContains($tree['child']->uuid, $uuids);
        $this->assertContains($tree['fil']->uuid, $uuids);
        $this->assertContains($tree['mil']->uuid, $uuids);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedExtendedFamily(string $viewerGender): array
    {
        $family = Family::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Seed Family',
            'member_count' => 0,
        ]);

        $viewer = User::factory()->create(['display_name' => 'Viewer']);
        $parentType = RelationshipEdgeType::query()->where('code', 'parent_of')->firstOrFail();
        $spouseType = RelationshipEdgeType::query()->where('code', 'spouse_of')->firstOrFail();

        $make = function (string $first, string $gender, ?User $user = null) use ($family): FamilyMember {
            return FamilyMember::query()->create([
                'uuid' => (string) Str::uuid(),
                'family_uuid' => $family->uuid,
                'user_id' => $user?->id,
                'first_name' => $first,
                'last_name' => 'X',
                'gender' => $gender,
                'is_living' => true,
            ]);
        };

        $self = $make('Self', $viewerGender, $viewer);
        $father = $make('Father', 'male');
        $mother = $make('Mother', 'female');
        $paternalGf = $make('PGF', 'male');
        $paternalGm = $make('PGM', 'female');
        $uncle = $make('Uncle', 'male');
        $maternalGf = $make('MGF', 'male');
        $maternalGm = $make('MGM', 'female');
        $maternalUncle = $make('MatUncle', 'male');
        $spouseGender = $viewerGender === 'male' ? 'female' : 'male';
        $spouse = $make('Spouse', $spouseGender);
        $child = $make('Child', 'male');
        $fil = $make('FIL', 'male');
        $mil = $make('MIL', 'female');

        $parent = function (FamilyMember $from, FamilyMember $to) use ($parentType): void {
            RelationshipEdge::query()->create([
                'uuid' => (string) Str::uuid(),
                'from_member_uuid' => $from->uuid,
                'to_member_uuid' => $to->uuid,
                'edge_type_id' => $parentType->id,
            ]);
        };
        $spouseEdge = function (FamilyMember $a, FamilyMember $b) use ($spouseType): void {
            RelationshipEdge::query()->create([
                'uuid' => (string) Str::uuid(),
                'from_member_uuid' => $a->uuid,
                'to_member_uuid' => $b->uuid,
                'edge_type_id' => $spouseType->id,
            ]);
        };

        $parent($father, $self);
        $parent($mother, $self);
        $parent($paternalGf, $father);
        $parent($paternalGm, $father);
        $parent($paternalGf, $uncle);
        $parent($paternalGm, $uncle);
        $parent($maternalGf, $mother);
        $parent($maternalGm, $mother);
        $parent($maternalGf, $maternalUncle);
        $parent($maternalGm, $maternalUncle);
        $spouseEdge($self, $spouse);
        $parent($self, $child);
        $parent($spouse, $child);
        $parent($fil, $spouse);
        $parent($mil, $spouse);
        $spouseEdge($father, $mother);

        $family->update([
            'member_count' => FamilyMember::query()->where('family_uuid', $family->uuid)->count(),
        ]);

        return [
            'viewer' => $viewer,
            'self' => $self,
            'father' => $father,
            'mother' => $mother,
            'paternal_gf' => $paternalGf,
            'paternal_gm' => $paternalGm,
            'uncle' => $uncle,
            'maternal_gf' => $maternalGf,
            'maternal_gm' => $maternalGm,
            'maternal_uncle' => $maternalUncle,
            'wife' => $spouse,
            'child' => $child,
            'fil' => $fil,
            'mil' => $mil,
        ];
    }
}

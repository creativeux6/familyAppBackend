<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\FamilyMember;
use App\Models\User;
use App\Models\UserDeclaredRelative;
use App\Modules\Onboarding\Services\FamilyMatcherService;
use Illuminate\Support\Str;

class DeclaredRelativeService
{
    public function __construct(
        private readonly FamilyMatcherService $matcher,
    ) {}

    private function graphService(): FamilyMemberGraphService
    {
        return app(FamilyMemberGraphService::class);
    }

    /** @param  array<int, array<string, mixed>>  $answers */
    public function syncFromOnboardingAnswers(User $user, array $answers): void
    {
        $slotToRelation = [
            'father' => ['father', 0],
            'mother' => ['mother', 0],
            'spouse' => ['spouse', 0],
            'spouse_father' => ['spouse_father', 0],
            'spouse_mother' => ['spouse_mother', 0],
            'paternal_grandfather' => ['paternal_grandfather', 0],
            'paternal_grandmother' => ['paternal_grandmother', 0],
            'maternal_grandfather' => ['maternal_grandfather', 0],
            'maternal_grandmother' => ['maternal_grandmother', 0],
            'other_relative' => ['other_relative', 0],
        ];

        foreach ($answers as $answer) {
            $slot = $answer['relative_slot'] ?? null;

            if ($slot === 'self' || $slot === null) {
                continue;
            }

            if ($slot === 'child') {
                $this->upsertDeclared(
                    $user,
                    'child',
                    (int) ($answer['relation_index'] ?? 0),
                    $answer,
                );

                continue;
            }

            if ($slot === 'sibling') {
                $this->upsertDeclared(
                    $user,
                    'sibling',
                    (int) ($answer['relation_index'] ?? 0),
                    $answer,
                );

                continue;
            }

            if (! isset($slotToRelation[$slot])) {
                continue;
            }

            [$relationType, $index] = $slotToRelation[$slot];
            $this->upsertDeclared($user, $relationType, $index, $answer);
        }
    }

    public function ensureDeclaredFromGraph(User $user, FamilyMember $selfMember): void
    {
        if (UserDeclaredRelative::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        $info = $this->graphService()->familyInfoForMember($selfMember, $user);
        $this->syncMatchingInfo($user, $info);
    }

    /** @param  array<string, mixed>  $data */
    public function syncMatchingInfo(User $user, array $data): void
    {
        $this->upsertDeclared($user, 'father', 0, $data['father'] ?? null);
        $this->upsertDeclared($user, 'mother', 0, $data['mother'] ?? null);
        $this->upsertDeclared($user, 'spouse', 0, $data['spouse'] ?? null);
        $this->upsertDeclared($user, 'spouse_father', 0, $data['spouse_father'] ?? null);
        $this->upsertDeclared($user, 'spouse_mother', 0, $data['spouse_mother'] ?? null);

        $children = $data['children'] ?? [];
        $existingChildren = UserDeclaredRelative::query()
            ->where('user_id', $user->id)
            ->where('relation_type', 'child')
            ->get();

        foreach ($children as $index => $childData) {
            if (! $this->graphService()->relativeHasInfo($childData)) {
                continue;
            }

            $this->upsertDeclared($user, 'child', $index, $childData);
        }

        foreach ($existingChildren as $existing) {
            if ($existing->relation_index >= count($children)) {
                $existing->delete();
            }
        }

        $siblings = $data['siblings'] ?? [];
        $existingSiblings = UserDeclaredRelative::query()
            ->where('user_id', $user->id)
            ->where('relation_type', 'sibling')
            ->get();

        foreach ($siblings as $index => $siblingData) {
            if (! $this->graphService()->relativeHasInfo($siblingData)) {
                continue;
            }

            $this->upsertDeclared($user, 'sibling', $index, $siblingData);
        }

        foreach ($existingSiblings as $existing) {
            if ($existing->relation_index >= count($siblings)) {
                $existing->delete();
            }
        }
    }

    /** @param  array<string, array<string, mixed>>  $parentContext */
    public function storeParentContext(User $user, array $parentContext): void
    {
        foreach (['mother', 'father', 'spouse'] as $slot) {
            if (! isset($parentContext[$slot])) {
                continue;
            }

            $this->upsertDeclared($user, $slot, 0, $parentContext[$slot]);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function getParentContext(User $user): array
    {
        $context = [];

        foreach (['mother', 'father', 'spouse'] as $slot) {
            $declared = UserDeclaredRelative::query()
                ->where('user_id', $user->id)
                ->where('relation_type', $slot)
                ->where('relation_index', 0)
                ->first();

            if (! $declared || ! $this->graphService()->relativeHasInfo([
                'first_name' => $declared->first_name,
                'last_name' => $declared->last_name,
            ])) {
                continue;
            }

            $context[$slot] = $this->formatRelative($declared);
        }

        return $context;
    }

    /** @return list<array<string, mixed>> */
    public function listDeclaredRelatives(User $user): array
    {
        return UserDeclaredRelative::query()
            ->where('user_id', $user->id)
            ->orderBy('relation_type')
            ->orderBy('relation_index')
            ->get()
            ->map(fn (UserDeclaredRelative $declared) => $this->formatRelative($declared))
            ->values()
            ->all();
    }

    /** @param  list<array<string, mixed>>  $relatives */
    public function syncDeclaredRelatives(User $user, array $relatives): array
    {
        foreach ($relatives as $relative) {
            $relationType = (string) ($relative['relation_type'] ?? '');
            if ($relationType === '') {
                continue;
            }

            $this->upsertDeclared(
                $user,
                $relationType,
                (int) ($relative['relation_index'] ?? 0),
                $relative,
            );
        }

        return $this->listDeclaredRelatives($user);
    }

    /** @return array<string, mixed> */
    public function formatRelative(UserDeclaredRelative $declared): array
    {
        return [
            'uuid' => $declared->uuid,
            'relation_type' => $declared->relation_type,
            'relation_index' => $declared->relation_index,
            'first_name' => (string) ($declared->first_name ?? ''),
            'last_name' => (string) ($declared->last_name ?? ''),
            'date_of_birth' => $declared->date_of_birth?->format('Y-m-d'),
            'date_of_death' => $declared->date_of_death?->format('Y-m-d'),
            'gender' => $declared->gender ?? 'unknown',
            'is_living' => $declared->is_living ?? true,
        ];
    }

    public function hasParentAnchors(User $user): bool
    {
        $context = $this->getParentContext($user);

        foreach (['mother', 'father'] as $slot) {
            $names = $context[$slot] ?? null;
            if ($names === null) {
                return false;
            }

            $first = trim((string) ($names['first_name'] ?? ''));
            $last = trim((string) ($names['last_name'] ?? ''));
            if ($first === '' && $last === '') {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, mixed>|null  $data */
    public function upsertDeclared(
        User $user,
        string $relationType,
        int $relationIndex,
        ?array $data,
        ?string $memberUuid = null,
    ): ?UserDeclaredRelative {
        if (! $this->graphService()->relativeHasInfo($data)) {
            UserDeclaredRelative::query()
                ->where('user_id', $user->id)
                ->where('relation_type', $relationType)
                ->where('relation_index', $relationIndex)
                ->delete();

            return null;
        }

        $relative = UserDeclaredRelative::query()
            ->where('user_id', $user->id)
            ->where('relation_type', $relationType)
            ->where('relation_index', $relationIndex)
            ->first();

        return UserDeclaredRelative::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'relation_type' => $relationType,
                'relation_index' => $relationIndex,
            ],
            [
                'uuid' => $relative?->uuid ?? (string) Str::uuid(),
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'maiden_name' => $data['maiden_name'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'birthplace' => $data['birthplace'] ?? null,
                'gender' => $data['gender'] ?? $this->defaultGenderForRelation($relationType),
                ...$this->vitalityAttributes($data),
                'member_uuid' => $memberUuid
                    ?? ($data['uuid'] ?? null)
                    ?? $relative?->member_uuid,
            ],
        );
    }

    public function linkMemberToDeclared(User $user, FamilyMember $member, string $relationType, int $relationIndex = 0): void
    {
        UserDeclaredRelative::query()
            ->where('user_id', $user->id)
            ->where('relation_type', $relationType)
            ->where('relation_index', $relationIndex)
            ->update(['member_uuid' => $member->uuid]);
    }

    /**
     * @param  array<string, mixed>  $selfAnswer
     * @return list<array<string, mixed>>
     */
    public function findCrossUserIdentityMatches(User $user, array $selfAnswer): array
    {
        $matches = [];

        $parentRelations = UserDeclaredRelative::query()
            ->where('user_id', '!=', $user->id)
            ->whereIn('relation_type', ['father', 'mother'])
            ->get();

        foreach ($parentRelations as $declared) {
            $score = $this->matcher->scoreAnswer($selfAnswer, $this->asPseudoMember($declared));
            if ($score < FamilyMatcherService::SELF_STUB_THRESHOLD) {
                continue;
            }

            $declarer = User::query()->find($declared->user_id);
            $declarerMember = FamilyMember::query()
                ->with('family')
                ->where('user_id', $declared->user_id)
                ->first();
            if (! $declarerMember) {
                continue;
            }

            $matches[] = [
                'member_uuid' => $declared->member_uuid,
                'family_uuid' => $declarerMember->family_uuid,
                'family_name' => $declarerMember->family?->name,
                'score' => round($score, 4),
                'relationship_hint' => $this->parentHintForRelation($declared->relation_type, $declarer->display_name ?? 'a family member'),
                'linked_relatives' => [[
                    'member_uuid' => $declarerMember->uuid,
                    'display_name' => $declarer->display_name,
                    'relationship' => $declared->relation_type === 'father' ? 'father of' : 'mother of',
                ]],
                'source' => 'declared_relative',
            ];
        }

        $childRelations = UserDeclaredRelative::query()
            ->where('user_id', '!=', $user->id)
            ->where('relation_type', 'child')
            ->get();

        foreach ($childRelations as $declared) {
            $score = $this->matcher->scoreAnswer($selfAnswer, $this->asPseudoMember($declared));
            if ($score < FamilyMatcherService::SELF_STUB_THRESHOLD) {
                continue;
            }

            $declarer = User::query()->find($declared->user_id);
            $declarerMember = FamilyMember::query()
                ->with('family')
                ->where('user_id', $declared->user_id)
                ->first();
            if (! $declarerMember) {
                continue;
            }

            $matches[] = [
                'member_uuid' => $declared->member_uuid,
                'family_uuid' => $declarerMember->family_uuid,
                'family_name' => $declarerMember->family?->name,
                'score' => round($score, 4),
                'relationship_hint' => 'You may be the parent of '.($declarer->display_name ?? 'someone in this family'),
                'linked_relatives' => [[
                    'member_uuid' => $declarerMember->uuid,
                    'display_name' => $declarer->display_name,
                    'relationship' => 'child of',
                ]],
                'source' => 'declared_relative',
            ];
        }

        return collect($matches)
            ->sortByDesc('score')
            ->unique('family_uuid')
            ->values()
            ->take(5)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return list<array<string, mixed>>
     */
    public function findCrossFamilyDeclaredMatches(
        ?User $viewer,
        array $answer,
        ?string $forRelationType,
        string $excludeFamilyUuid,
    ): array {
        if (! $viewer) {
            return [];
        }

        $matches = [];
        $declared = UserDeclaredRelative::query()
            ->where('user_id', '!=', $viewer->id)
            ->whereIn('relation_type', ['father', 'mother', 'sibling', 'spouse', 'child'])
            ->get();

        foreach ($declared as $row) {
            $score = $this->matcher->scoreAnswer($answer, $this->asPseudoMember($row));
            if ($score < FamilyMatcherService::SELF_STUB_THRESHOLD) {
                continue;
            }

            $member = $row->member_uuid
                ? FamilyMember::query()->with('family')->find($row->member_uuid)
                : null;

            if (! $member || $member->family_uuid === $excludeFamilyUuid) {
                continue;
            }

            $declarer = User::query()->find($row->user_id);
            $declarerMember = FamilyMember::query()->where('user_id', $row->user_id)->first();
            $declarerName = $declarer?->display_name
                ?? trim(($declarerMember?->first_name ?? '').' '.($declarerMember?->last_name ?? ''));

            $matches[] = [
                'member_uuid' => $member->uuid,
                'first_name' => $member->first_name,
                'last_name' => $member->last_name,
                'date_of_birth' => $member->date_of_birth?->format('Y-m-d'),
                'gender' => $member->gender,
                'is_registered' => $member->user_id !== null,
                'match_score' => round($score, 2),
                'kinship_label' => 'In another family',
                'existing_relationships' => [
                    'In '.($member->family?->name ?? 'another family'),
                    $this->declaredRelationHint($row->relation_type, $declarerName),
                ],
                'adding_as' => $forRelationType,
                'is_cross_family' => true,
                'family_uuid' => $member->family_uuid,
                'family_name' => $member->family?->name,
            ];
        }

        return $matches;
    }

    private function declaredRelationHint(string $relationType, string $declarerName): string
    {
        return match ($relationType) {
            'sibling' => "Sibling of {$declarerName}",
            'mother' => "Mother of {$declarerName}",
            'father' => "Father of {$declarerName}",
            'spouse' => "Spouse of {$declarerName}",
            'child' => "Child of {$declarerName}",
            default => "Related to {$declarerName}",
        };
    }

    private function parentHintForRelation(string $relationType, string $name): string
    {
        return match ($relationType) {
            'father' => "You may be the father of {$name}",
            'mother' => "You may be the mother of {$name}",
            default => "You may be related to {$name}",
        };
    }

    private function asPseudoMember(UserDeclaredRelative $declared): FamilyMember
    {
        $member = new FamilyMember([
            'first_name' => $declared->first_name ?? '',
            'last_name' => $declared->last_name ?? '',
            'date_of_birth' => $declared->date_of_birth,
            'gender' => $declared->gender,
        ]);

        return $member;
    }

    private function defaultGenderForRelation(string $relationType): string
    {
        return match ($relationType) {
            'father', 'spouse_father', 'paternal_grandfather', 'maternal_grandfather' => 'male',
            'mother', 'spouse_mother', 'paternal_grandmother', 'maternal_grandmother' => 'female',
            default => 'unknown',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{is_living: bool, date_of_death: ?string}
     */
    private function vitalityAttributes(array $data): array
    {
        $isLiving = array_key_exists('is_living', $data)
            ? (bool) $data['is_living']
            : true;

        return [
            'is_living' => $isLiving,
            'date_of_death' => $isLiving ? null : ($data['date_of_death'] ?? null),
        ];
    }
}

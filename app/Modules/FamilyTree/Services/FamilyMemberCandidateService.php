<?php

namespace App\Modules\FamilyTree\Services;

use App\Contracts\FamilyGraph\FamilyGraphRepositoryInterface;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;
use App\Models\UserDeclaredRelative;
use App\Modules\FamilyTree\Enums\TreeViewMode;
use App\Modules\Onboarding\Services\FamilyMatcherService;
use Illuminate\Support\Collection;

class FamilyMemberCandidateService
{
    public function __construct(
        private readonly FamilyMatcherService $matcher,
        private readonly FamilyGraphRepositoryInterface $graphRepository,
        private readonly DeclaredRelativeService $declaredRelatives,
    ) {}

    /**
     * @param  array<string, mixed>  $answer
     * @return list<array<string, mixed>>
     */
    public function findCandidates(
        FamilyMember $viewer,
        array $answer,
        ?string $excludeUuid = null,
        ?string $forRelationType = null,
    ): array {
        if (! $this->answerHasMatchableInfo($answer)) {
            return [];
        }

        $sameFamily = $this->findSameFamilyCandidates($viewer, $answer, $excludeUuid, $forRelationType);
        $crossFamily = $this->findCrossFamilyCandidates($viewer, $answer, $excludeUuid, $forRelationType);

        return collect($sameFamily)
            ->merge($crossFamily)
            ->unique('member_uuid')
            ->sortByDesc('match_score')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return list<array<string, mixed>>
     */
    private function findSameFamilyCandidates(
        FamilyMember $viewer,
        array $answer,
        ?string $excludeUuid,
        ?string $forRelationType,
    ): array {
        $graph = $this->graphRepository->loadFamilyGraph($viewer->family_uuid);
        $membersByUuid = $graph['members']->keyBy('uuid');
        $edges = $graph['edges'];

        return FamilyMember::query()
            ->where('family_uuid', $viewer->family_uuid)
            ->where('uuid', '!=', $viewer->uuid)
            ->when($excludeUuid, fn ($query) => $query->where('uuid', '!=', $excludeUuid))
            ->get()
            ->filter(fn (FamilyMember $member) => $this->isLikelyDuplicate($answer, $member))
            ->map(fn (FamilyMember $member) => $this->formatCandidate(
                $viewer,
                $member,
                $answer,
                $forRelationType,
                $edges,
                $membersByUuid,
                false,
            ))
            ->sortByDesc('match_score')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return list<array<string, mixed>>
     */
    private function findCrossFamilyCandidates(
        FamilyMember $viewer,
        array $answer,
        ?string $excludeUuid,
        ?string $forRelationType,
    ): array {
        $candidates = FamilyMember::query()
            ->where('family_uuid', '!=', $viewer->family_uuid)
            ->when($excludeUuid, fn ($query) => $query->where('uuid', '!=', $excludeUuid))
            ->get()
            ->filter(fn (FamilyMember $member) => $this->isLikelyDuplicate($answer, $member))
            ->map(function (FamilyMember $member) use ($viewer, $answer, $forRelationType) {
                $graph = $this->graphRepository->loadFamilyGraph($member->family_uuid);
                $family = Family::query()->find($member->family_uuid);

                return $this->formatCandidate(
                    $viewer,
                    $member,
                    $answer,
                    $forRelationType,
                    $graph['edges'],
                    $graph['members']->keyBy('uuid'),
                    true,
                    $family?->name,
                );
            })
            ->values()
            ->all();

        $declaredMatches = $this->declaredRelatives->findCrossFamilyDeclaredMatches(
            $viewer->user_id ? User::query()->find($viewer->user_id) : null,
            $answer,
            $forRelationType,
            $viewer->family_uuid,
        );

        return collect($candidates)
            ->merge($declaredMatches)
            ->unique('member_uuid')
            ->sortByDesc('match_score')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, FamilyMember>  $membersByUuid
     * @return array<string, mixed>
     */
    private function formatCandidate(
        FamilyMember $viewer,
        FamilyMember $member,
        array $answer,
        ?string $forRelationType,
        Collection $edges,
        Collection $membersByUuid,
        bool $isCrossFamily,
        ?string $familyName = null,
    ): array {
        $kinship = $isCrossFamily
            ? ['kinship_label' => 'In another family', 'path_found' => false]
            : $this->graphRepository->resolveKinship(
                $viewer->uuid,
                $member->uuid,
                TreeViewMode::All,
                $edges,
            );

        $existingRelationships = $this->describeExistingRelationships($member, $edges, $membersByUuid);
        if ($isCrossFamily && $familyName) {
            array_unshift($existingRelationships, "In {$familyName}");
        }

        return [
            'member_uuid' => $member->uuid,
            'first_name' => $member->first_name,
            'last_name' => $member->last_name,
            'date_of_birth' => $member->date_of_birth?->format('Y-m-d'),
            'gender' => $member->gender,
            'is_registered' => $member->user_id !== null,
            'match_score' => round($this->matcher->scoreAnswer($answer, $member), 2),
            'kinship_label' => $kinship['kinship_label'],
            'existing_relationships' => $existingRelationships,
            'adding_as' => $forRelationType,
            'is_cross_family' => $isCrossFamily,
            'family_uuid' => $member->family_uuid,
            'family_name' => $familyName,
        ];
    }

    /** @param  array<string, mixed>  $answer */
    public function isLikelyDuplicate(array $answer, FamilyMember $member): bool
    {
        $firstName = trim((string) ($answer['first_name'] ?? ''));
        $lastName = trim((string) ($answer['last_name'] ?? ''));

        if (
            $firstName !== ''
            && $lastName !== ''
            && strcasecmp($firstName, $member->first_name) === 0
            && strcasecmp($lastName, $member->last_name) === 0
        ) {
            return true;
        }

        $score = $this->matcher->scoreAnswer($answer, $member);

        if ($firstName !== '' && $lastName !== '') {
            return strcasecmp($firstName, $member->first_name) === 0
                && $score >= FamilyMatcherService::SELF_STUB_THRESHOLD;
        }

        return $score >= FamilyMatcherService::SELF_STUB_THRESHOLD;
    }

    /** @param  array<string, mixed>  $answer */
    private function answerHasMatchableInfo(array $answer): bool
    {
        foreach (['first_name', 'last_name', 'date_of_birth'] as $field) {
            if (! empty($answer[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<string, FamilyMember>  $membersByUuid
     * @return list<string>
     */
    private function describeExistingRelationships(
        FamilyMember $candidate,
        Collection $edges,
        Collection $membersByUuid,
    ): array {
        $relatedUuids = $this->relatedMemberUuids($candidate->uuid, $edges);
        $hints = [];

        foreach ($relatedUuids as $relatedUuid) {
            /** @var FamilyMember|null $related */
            $related = $membersByUuid->get($relatedUuid);
            if (! $related) {
                continue;
            }

            $kinship = $this->graphRepository->resolveKinship(
                $related->uuid,
                $candidate->uuid,
                TreeViewMode::All,
                $edges,
            );

            if (! $kinship['path_found']) {
                continue;
            }

            $label = $kinship['kinship_label'];
            if ($label === '' || $label === 'Relative') {
                continue;
            }

            $relatedName = trim($related->first_name.' '.$related->last_name);
            if ($relatedName === '') {
                $relatedName = 'a family member';
            }

            $hints[] = "{$label} of {$relatedName}";
        }

        return array_values(array_unique($hints));
    }

    /** @return list<string> */
    private function relatedMemberUuids(string $memberUuid, Collection $edges): array
    {
        $related = [];

        foreach ($edges as $edge) {
            if ($edge->from_member_uuid === $memberUuid) {
                $related[] = $edge->to_member_uuid;
            } elseif ($edge->to_member_uuid === $memberUuid) {
                $related[] = $edge->from_member_uuid;
            }
        }

        return array_values(array_unique($related));
    }
}

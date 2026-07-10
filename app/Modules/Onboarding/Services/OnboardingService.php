<?php

namespace App\Modules\Onboarding\Services;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\OnboardingAnswer;
use App\Models\OnboardingSession;
use App\Models\RelationshipEdge;
use App\Models\RelationshipEdgeType;
use App\Models\User;
use App\Modules\FamilyTree\Services\DeclaredRelativeService;
use App\Modules\FamilyTree\Services\FamilyGraphMaterializationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnboardingService
{
    public function __construct(
        private readonly FamilyMatcherService $matcher,
        private readonly DeclaredRelativeService $declaredRelatives,
        private readonly FamilyGraphMaterializationService $graphMaterialization,
    ) {}

    /** @param  array<int, array<string, mixed>>  $answers */
    public function submitQuestionnaire(
        User $user,
        array $answers,
        ?string $maritalStatus = null,
    ): array {
        if (OnboardingSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'confirmed')
            ->exists()) {
            throw ValidationException::withMessages([
                'onboarding' => ['Onboarding is already complete for this account.'],
            ]);
        }

        return DB::transaction(function () use ($user, $answers, $maritalStatus) {
            OnboardingSession::query()
                ->where('user_id', $user->id)
                ->whereIn('status', ['in_progress', 'matched'])
                ->update(['status' => 'rejected', 'completed_at' => now()]);

            $session = OnboardingSession::create([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'status' => 'in_progress',
            ]);

            foreach ($answers as $answer) {
                OnboardingAnswer::create([
                    'onboarding_session_uuid' => $session->uuid,
                    'relation_index' => $answer['relation_index'] ?? 0,
                    ...$answer,
                ]);
            }

            if ($maritalStatus !== null) {
                $user->update(['marital_status' => $maritalStatus]);
            }

            $this->declaredRelatives->syncFromOnboardingAnswers($user, $answers);

            $selfAnswer = collect($answers)->firstWhere('relative_slot', 'self') ?? [];
            $match = $this->matcher->match($answers);
            $identityMatches = $this->matcher->findSelfStubMatches($selfAnswer);
            $declaredMatches = $this->declaredRelatives->findCrossUserIdentityMatches($user, $selfAnswer);
            $identityMatches = $this->mergeIdentityMatches($identityMatches, $declaredMatches);
            $relativeMatches = $this->matcher->findCrossFamilyRelativeMatches($answers);

            $matchedFamily = $match['family'];
            $topScore = $match['score'];
            $isNewFamily = $matchedFamily === null;

            if ($identityMatches !== []) {
                $bestIdentity = $identityMatches[0];
                $identityFamily = Family::query()->find($bestIdentity['family_uuid']);

                if ($identityFamily && ($matchedFamily === null || $bestIdentity['score'] >= $topScore)) {
                    $matchedFamily = $identityFamily;
                    $topScore = $bestIdentity['score'];
                    $isNewFamily = false;
                }
            }

            if ($matchedFamily && ! $isNewFamily) {
                $session->update([
                    'status' => 'matched',
                    'matched_family_uuid' => $matchedFamily->uuid,
                    'top_match_score' => $topScore,
                    'match_candidates' => $match['candidates'],
                ]);

                return $this->formatSessionResponse(
                    $session->fresh(['matchedFamily']),
                    $matchedFamily,
                    identityMatches: $identityMatches,
                    relativeMatches: $relativeMatches,
                );
            }

            $family = Family::create([
                'uuid' => (string) Str::uuid(),
                'name' => $this->deriveFamilyName($answers),
                'slug' => Str::slug($this->deriveFamilyName($answers).'-'.Str::random(6)),
                'member_count' => 0,
            ]);

            $membersBySlot = $this->createMembersFromAnswers($family, $answers, $user);
            $this->createRelationshipEdges($membersBySlot, $user->id);
            $this->linkDeclaredRelativesToMembers($user, $membersBySlot);

            $family->update(['member_count' => $family->members()->count()]);

            $session->update([
                'status' => 'matched',
                'matched_family_uuid' => $family->uuid,
                'top_match_score' => $isNewFamily ? 1.0 : $topScore,
                'match_candidates' => $match['candidates'],
            ]);

            return $this->formatSessionResponse(
                $session->fresh(['matchedFamily']),
                $family,
                is_new_family: $isNewFamily,
                identityMatches: $identityMatches,
                relativeMatches: $relativeMatches,
            );
        });
    }

    public function latestSession(User $user): array
    {
        $session = OnboardingSession::query()
            ->where('user_id', $user->id)
            ->latest()
            ->with('matchedFamily')
            ->first();

        if (! $session) {
            throw ValidationException::withMessages([
                'onboarding' => ['No onboarding session found. Submit questionnaire first.'],
            ]);
        }

        $family = $session->matchedFamily;

        return $this->formatSessionResponse($session, $family);
    }

    public function confirmFamily(User $user, bool $confirmed): array
    {
        $session = OnboardingSession::query()
            ->where('user_id', $user->id)
            ->where('status', 'matched')
            ->latest()
            ->first();

        if (! $session || ! $session->matched_family_uuid) {
            throw ValidationException::withMessages([
                'onboarding' => ['No matched family to confirm.'],
            ]);
        }

        if (! $confirmed) {
            $session->update(['status' => 'rejected', 'completed_at' => now()]);

            return ['status' => 'rejected', 'message' => 'Family affiliation rejected.'];
        }

        $matchedFamily = Family::query()->findOrFail($session->matched_family_uuid);
        $selfAnswer = OnboardingAnswer::query()
            ->where('onboarding_session_uuid', $session->uuid)
            ->where('relative_slot', 'self')
            ->first();

        $selfAnswerData = $selfAnswer ? [
            'first_name' => $selfAnswer->first_name,
            'last_name' => $selfAnswer->last_name,
            'date_of_birth' => $selfAnswer->date_of_birth?->format('Y-m-d'),
            'birthplace' => $selfAnswer->birthplace,
            'gender' => 'unknown',
            'is_living' => $selfAnswer->is_living ?? true,
        ] : [];

        $claimedMember = DB::transaction(function () use ($user, $matchedFamily, $session, $selfAnswerData) {
            $selfMember = FamilyMember::query()
                ->where('user_id', $user->id)
                ->first();

            if ($selfMember && $selfMember->family_uuid !== $matchedFamily->uuid) {
                $member = $this->matcher->claimSelfStub($user, $matchedFamily, $selfMember);
            } elseif ($selfMember) {
                $member = $selfMember;
            } else {
                $member = $this->matcher->joinExistingFamily($user, $matchedFamily, $selfAnswerData);
            }

            $answers = OnboardingAnswer::query()
                ->where('onboarding_session_uuid', $session->uuid)
                ->get()
                ->map(fn (OnboardingAnswer $answer) => [
                    'relative_slot' => $answer->relative_slot,
                    'relation_index' => $answer->relation_index,
                    'first_name' => $answer->first_name,
                    'last_name' => $answer->last_name,
                    'maiden_name' => $answer->maiden_name,
                    'date_of_birth' => $answer->date_of_birth?->format('Y-m-d'),
                    'date_of_death' => $answer->date_of_death?->format('Y-m-d'),
                    'birthplace' => $answer->birthplace,
                    'gender' => $answer->gender,
                    'is_living' => $answer->is_living,
                ])
                ->all();

            $this->graphMaterialization->materializeOnboardingAnswers($user, $member, $answers);

            $matchedFamily->update(['member_count' => $matchedFamily->members()->count()]);
            $session->update(['status' => 'confirmed', 'completed_at' => now()]);

            return $member;
        });

        return [
            'status' => 'confirmed',
            'family_uuid' => $matchedFamily->uuid,
            'member_uuid' => $claimedMember->uuid,
            'message' => 'You belong to this family.',
            'active_members' => $this->matcher->activeMembersForFamily($matchedFamily)->values()->all(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $stubMatches
     * @param  list<array<string, mixed>>  $declaredMatches
     * @return list<array<string, mixed>>
     */
    private function mergeIdentityMatches(array $stubMatches, array $declaredMatches): array
    {
        return collect($stubMatches)
            ->concat($declaredMatches)
            ->sortByDesc('score')
            ->unique('family_uuid')
            ->values()
            ->take(5)
            ->all();
    }

    /** @param  array<string, FamilyMember>  $membersBySlot */
    private function linkDeclaredRelativesToMembers(User $user, array $membersBySlot): void
    {
        foreach ($membersBySlot as $key => $member) {
            if ($key === 'self') {
                continue;
            }

            if (str_starts_with($key, 'child_')) {
                $index = (int) str_replace('child_', '', $key);
                $this->declaredRelatives->linkMemberToDeclared($user, $member, 'child', $index);

                continue;
            }

            if (str_starts_with($key, 'sibling_')) {
                continue;
            }

            $relationType = match ($key) {
                'father', 'mother', 'spouse', 'spouse_father', 'spouse_mother' => $key,
                'paternal_grandfather', 'paternal_grandmother',
                'maternal_grandfather', 'maternal_grandmother', 'other_relative' => $key,
                default => null,
            };

            if ($relationType) {
                $this->declaredRelatives->linkMemberToDeclared($user, $member, $relationType);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $identityMatches
     * @return array<string, mixed>
     */
    private function formatSessionResponse(
        OnboardingSession $session,
        ?Family $family,
        bool $is_new_family = false,
        array $identityMatches = [],
        array $relativeMatches = [],
    ): array {
        return [
            'session_uuid' => $session->uuid,
            'status' => $session->status,
            'is_new_family' => $is_new_family,
            'matched_family' => $family ? [
                'uuid' => $family->uuid,
                'name' => $family->name,
            ] : null,
            'top_match_score' => $session->top_match_score,
            'match_candidates' => $session->match_candidates ?? [],
            'identity_matches' => $identityMatches,
            'relative_matches' => $relativeMatches,
            'active_members' => $family
                ? $this->matcher->activeMembersForFamily($family)->values()->all()
                : [],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $answers */
    private function deriveFamilyName(array $answers): string
    {
        $self = collect($answers)->firstWhere('relative_slot', 'self');

        return ($self['last_name'] ?? 'Family').' Family';
    }

    /** @return array<string, FamilyMember> */
    private function createMembersFromAnswers(Family $family, array $answers, User $user): array
    {
        $bySlot = [];
        $existingSelfMember = FamilyMember::query()->where('user_id', $user->id)->first();

        foreach ($answers as $answer) {
            $slot = $answer['relative_slot'];

            if ($slot !== 'self' && ! $this->answerHasInfo($answer)) {
                continue;
            }

            $key = $this->memberKeyForAnswer($answer);

            if ($slot === 'self' && $existingSelfMember) {
                $existingSelfMember->update($this->memberAttributesFromAnswer($family, $answer));
                $bySlot['self'] = $existingSelfMember->fresh();

                continue;
            }

            $bySlot[$key] = FamilyMember::create([
                'uuid' => (string) Str::uuid(),
                ...$this->memberAttributesFromAnswer($family, $answer),
                'user_id' => $slot === 'self' ? $user->id : null,
            ]);
        }

        return $bySlot;
    }

    /** @param  array<string, mixed>  $answer */
    private function answerHasInfo(array $answer): bool
    {
        if (($answer['relative_slot'] ?? null) === 'self') {
            return true;
        }

        foreach (['first_name', 'last_name', 'date_of_birth'] as $field) {
            if (! empty($answer[$field])) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $answer */
    private function memberKeyForAnswer(array $answer): string
    {
        $slot = $answer['relative_slot'];

        if (in_array($slot, ['child', 'sibling', 'other_relative'], true)) {
            return $slot.'_'.($answer['relation_index'] ?? 0);
        }

        return $slot;
    }

    /** @param  array<string, mixed>  $answer */
    private function memberAttributesFromAnswer(Family $family, array $answer): array
    {
        return [
            'family_uuid' => $family->uuid,
            'first_name' => $answer['first_name'] ?? 'Unknown',
            'last_name' => $answer['last_name'] ?? 'Unknown',
            'maiden_name' => $answer['maiden_name'] ?? null,
            'date_of_birth' => $answer['date_of_birth'] ?? null,
            'birthplace' => $answer['birthplace'] ?? null,
            'gender' => $answer['gender'] ?? $this->defaultGenderForSlot($answer['relative_slot'] ?? ''),
            'is_living' => $answer['is_living'] ?? true,
            'date_of_death' => ($answer['is_living'] ?? true)
                ? null
                : ($answer['date_of_death'] ?? null),
        ];
    }

    private function defaultGenderForSlot(string $slot): string
    {
        return match ($slot) {
            'father', 'paternal_grandfather', 'maternal_grandfather', 'spouse_father' => 'male',
            'mother', 'paternal_grandmother', 'maternal_grandmother', 'spouse_mother' => 'female',
            default => 'unknown',
        };
    }

    /** @param  array<string, FamilyMember>  $membersBySlot */
    private function createRelationshipEdges(array $membersBySlot, int $userId): void
    {
        $parentType = RelationshipEdgeType::query()->where('code', 'parent_of')->firstOrFail();
        $spouseType = RelationshipEdgeType::query()->where('code', 'spouse_of')->firstOrFail();

        $parentChild = [
            ['father', 'self'],
            ['mother', 'self'],
            ['paternal_grandfather', 'father'],
            ['paternal_grandmother', 'father'],
            ['maternal_grandfather', 'mother'],
            ['maternal_grandmother', 'mother'],
            ['spouse_father', 'spouse'],
            ['spouse_mother', 'spouse'],
        ];

        foreach ($parentChild as [$parentSlot, $childSlot]) {
            if (! isset($membersBySlot[$parentSlot], $membersBySlot[$childSlot])) {
                continue;
            }

            $this->createParentEdge(
                $membersBySlot[$parentSlot],
                $membersBySlot[$childSlot],
                $parentType->id,
                $userId,
            );
        }

        if (isset($membersBySlot['self'], $membersBySlot['spouse'])) {
            RelationshipEdge::create([
                'uuid' => (string) Str::uuid(),
                'from_member_uuid' => $membersBySlot['self']->uuid,
                'to_member_uuid' => $membersBySlot['spouse']->uuid,
                'edge_type_id' => $spouseType->id,
                'created_by_user_id' => $userId,
            ]);
        }

        foreach ($membersBySlot as $key => $member) {
            if (! str_starts_with($key, 'child_')) {
                continue;
            }

            if (isset($membersBySlot['self'])) {
                $this->createParentEdge(
                    $membersBySlot['self'],
                    $member,
                    $parentType->id,
                    $userId,
                );
            }

            if (isset($membersBySlot['spouse'])) {
                $this->createParentEdge(
                    $membersBySlot['spouse'],
                    $member,
                    $parentType->id,
                    $userId,
                );
            }
        }
    }

    private function createParentEdge(
        FamilyMember $parent,
        FamilyMember $child,
        int $edgeTypeId,
        int $userId,
    ): void {
        RelationshipEdge::create([
            'uuid' => (string) Str::uuid(),
            'from_member_uuid' => $parent->uuid,
            'to_member_uuid' => $child->uuid,
            'edge_type_id' => $edgeTypeId,
            'created_by_user_id' => $userId,
        ]);
    }
}

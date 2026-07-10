<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
use Illuminate\Validation\ValidationException;

/**
 * Single catalog for join/search relation paths across mother, father, spouse, and sibling lines.
 */
class FamilyRelationPathService
{
    public function __construct(
        private readonly FamilyMemberGraphService $graph,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $answers
     * @return list<string>
     */
    public function requiredAnchorsForSearch(array $answers): array
    {
        $anchors = ['mother', 'father'];
        $slots = collect($answers)->pluck('relative_slot')->filter()->unique()->values()->all();

        foreach ($slots as $slot) {
            if (in_array($slot, ['spouse', 'spouse_father', 'spouse_mother'], true)) {
                $anchors[] = 'spouse';
            }
        }

        return array_values(array_unique($anchors));
    }

    /**
     * @return list<string>
     */
    public function requiredAnchorsForJoinCode(string $code): array
    {
        return match ($code) {
            'cousin_mother_brother_child', 'cousin_mother_sister_child',
            'cousin_father_brother_child', 'cousin_father_sister_child',
            'uncle_mother_brother', 'uncle_mother_sister_husband',
            'aunt_mother_sister', 'aunt_mother_brother_wife',
            'uncle_father_brother', 'uncle_father_sister_husband',
            'aunt_father_sister', 'aunt_father_brother_wife',
            'sibling', 'half_sibling_mother', 'half_sibling_father',
            'father', 'mother', 'step_father', 'step_mother',
            'child' => ['mother', 'father'],
            'grandfather_maternal', 'grandmother_maternal' => ['mother'],
            'grandfather_paternal', 'grandmother_paternal' => ['father'],
            'spouse' => ['mother', 'father'],
            'spouse_father', 'spouse_mother' => ['mother', 'father', 'spouse'],
            default => [],
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $context
     */
    public function assertRequiredAnchors(array $required, array $context): void
    {
        $errors = [];
        $labels = [
            'mother' => 'mother',
            'father' => 'father',
            'spouse' => 'spouse',
        ];

        foreach ($required as $slot) {
            $data = $context[$slot] ?? [];
            $first = trim((string) ($data['first_name'] ?? ''));
            $last = trim((string) ($data['last_name'] ?? ''));

            if ($first === '' && $last === '') {
                $errors["parent_context.{$slot}"] = [
                    'Enter your '.($labels[$slot] ?? $slot)."'s name so we can find the right family link.",
                ];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $context
     * @return array{mother: ?FamilyMember, father: ?FamilyMember, spouse: ?FamilyMember}
     */
    public function resolveAnchorsInFamily(FamilyMember $anchor, array $context): array
    {
        return [
            'mother' => $this->findInFamily($anchor, $context['mother'] ?? null),
            'father' => $this->findInFamily($anchor, $context['father'] ?? null),
            'spouse' => $this->findInFamily($anchor, $context['spouse'] ?? null),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $context
     * @return array{fits: bool, score: float, connection_path: ?string, suggested_join_code: ?string}
     */
    public function scoreSearchMatch(FamilyMember $match, string $searchSlot, array $context): array
    {
        if ($context === []) {
            return ['fits' => true, 'score' => 0.5, 'connection_path' => null, 'suggested_join_code' => null];
        }

        $anchors = $this->resolveAnchorsInFamily($match, $context);

        return match ($searchSlot) {
            'cousin', 'uncle', 'aunt' => $this->scoreExtendedKinMatch($match, $anchors, $searchSlot),
            'sibling' => $this->scoreSiblingMatch($match, $anchors),
            'father' => $this->scoreParentMatch($match, $anchors['father'], $anchors['mother'], 'father'),
            'mother' => $this->scoreParentMatch($match, $anchors['mother'], $anchors['father'], 'mother'),
            'spouse' => $this->scoreSpouseMatch($match, $anchors['spouse']),
            'spouse_father', 'spouse_mother' => $this->scoreSpouseInLawMatch($match, $anchors, $searchSlot),
            'child' => $this->scoreChildMatch($match, $anchors),
            'paternal_grandfather', 'paternal_grandmother' => $this->scoreGrandparentMatch($match, $anchors, 'paternal', $searchSlot),
            'maternal_grandfather', 'maternal_grandmother' => $this->scoreGrandparentMatch($match, $anchors, 'maternal', $searchSlot),
            'other_relative' => $this->scoreBestKinMatch($match, $anchors),
            default => $this->scoreBestKinMatch($match, $anchors),
        };
    }

    /**
     * @param  array<string, array<string, mixed>>  $context
     * @return array{ok: bool, message: string, resolved: array{mother: ?FamilyMember, father: ?FamilyMember, spouse: ?FamilyMember}}
     */
    public function verifyJoinPath(FamilyMember $target, string $relationCode, array $context): array
    {
        $required = $this->requiredAnchorsForJoinCode($relationCode);
        $this->assertRequiredAnchors($required, $context);

        $resolved = $this->resolveAnchorsInFamily($target, $context);

        if ($required === []) {
            return ['ok' => true, 'message' => 'Ready to connect.', 'resolved' => $resolved];
        }

        $search = $this->scoreSearchMatch($target, $this->searchSlotForJoinCode($relationCode), $context);
        if (! $search['fits']) {
            return [
                'ok' => false,
                'message' => $search['connection_path'] ?? 'This person does not match the family line for the names you entered.',
                'resolved' => $resolved,
            ];
        }

        if (! $this->joinCodesCompatible($relationCode, $search['suggested_join_code'] ?? null)) {
            return [
                'ok' => false,
                'message' => 'The family line fits, but a different relation option matches better: '
                    .str_replace('_', ' ', (string) $search['suggested_join_code']).'.',
                'resolved' => $resolved,
            ];
        }

        return [
            'ok' => true,
            'message' => $search['connection_path'] ?? 'Family line verified.',
            'resolved' => $resolved,
        ];
    }

    public function searchSlotForJoinCode(string $code): string
    {
        return match (true) {
            str_starts_with($code, 'cousin_') => 'cousin',
            str_starts_with($code, 'uncle_') => 'uncle',
            str_starts_with($code, 'aunt_') => 'aunt',
            $code === 'grandfather_paternal' => 'paternal_grandfather',
            $code === 'grandmother_paternal' => 'paternal_grandmother',
            $code === 'grandfather_maternal' => 'maternal_grandfather',
            $code === 'grandmother_maternal' => 'maternal_grandmother',
            str_starts_with($code, 'half_sibling_') => 'sibling',
            $code === 'step_father' => 'father',
            $code === 'step_mother' => 'mother',
            default => $code,
        };
    }

    /**
     * @param  array{mother: ?FamilyMember, father: ?FamilyMember, spouse: ?FamilyMember}  $anchors
     * @return array{fits: bool, score: float, connection_path: ?string, suggested_join_code: ?string}
     */
    private function scoreSiblingMatch(FamilyMember $match, array $anchors): array
    {
        $mother = $anchors['mother'];
        $father = $anchors['father'];

        if (! $mother && ! $father) {
            return [
                'fits' => false,
                'score' => 0.0,
                'connection_path' => 'Enter your mother and father to find the correct brother or sister.',
                'suggested_join_code' => null,
            ];
        }

        $sharesMother = $mother && $this->sharesParentLine($match, $mother);
        $sharesFather = $father && $this->sharesParentLine($match, $father);

        if ($sharesMother && $sharesFather) {
            return [
                'fits' => true,
                'score' => 1.0,
                'connection_path' => 'Full sibling line: shares both your mother and father in this tree.',
                'suggested_join_code' => 'sibling',
            ];
        }

        if ($sharesMother) {
            return [
                'fits' => true,
                'score' => 0.9,
                'connection_path' => "Mother's side sibling: shares your mother ({$this->fullName($mother)}) in this tree.",
                'suggested_join_code' => 'half_sibling_mother',
            ];
        }

        if ($sharesFather) {
            return [
                'fits' => true,
                'score' => 0.9,
                'connection_path' => "Father's side sibling: shares your father ({$this->fullName($father)}) in this tree.",
                'suggested_join_code' => 'half_sibling_father',
            ];
        }

        return [
            'fits' => false,
            'score' => 0.0,
            'connection_path' => 'This person does not share a parent line with the mother/father you entered.',
            'suggested_join_code' => null,
        ];
    }

    /**
     * @param  array{mother: ?FamilyMember, father: ?FamilyMember, spouse: ?FamilyMember}  $anchors
     */
    private function scoreParentMatch(
        FamilyMember $match,
        ?FamilyMember $parent,
        ?FamilyMember $otherParent,
        string $side,
    ): array {
        if (! $parent) {
            return [
                'fits' => false,
                'score' => 0.0,
                'connection_path' => "Enter your {$side}'s name to verify this match.",
                'suggested_join_code' => null,
            ];
        }

        if ($parent->uuid !== $match->uuid) {
            return [
                'fits' => false,
                'score' => 0.0,
                'connection_path' => "This person is not the {$side} you entered ({$this->fullName($parent)}).",
                'suggested_join_code' => null,
            ];
        }

        if ($otherParent && $this->areSpouses($parent, $otherParent)) {
            return [
                'fits' => true,
                'score' => 1.0,
                'connection_path' => "Matches your {$side} and is linked to your other parent in this tree.",
                'suggested_join_code' => $side,
            ];
        }

        return [
            'fits' => true,
            'score' => 0.85,
            'connection_path' => "Matches your {$side} in this tree.",
            'suggested_join_code' => $side,
        ];
    }

    /**
     * @return array{fits: bool, score: float, connection_path: ?string, suggested_join_code: ?string}
     */
    private function scoreSpouseMatch(FamilyMember $match, ?FamilyMember $spouse): array
    {
        if (! $spouse) {
            return [
                'fits' => false,
                'score' => 0.0,
                'connection_path' => 'Enter your spouse name to verify this match.',
                'suggested_join_code' => null,
            ];
        }

        if ($spouse->uuid !== $match->uuid) {
            return [
                'fits' => false,
                'score' => 0.0,
                'connection_path' => 'This person is not the spouse name you entered.',
                'suggested_join_code' => null,
            ];
        }

        return [
            'fits' => true,
            'score' => 1.0,
            'connection_path' => 'Matches your spouse in this tree.',
            'suggested_join_code' => 'spouse',
        ];
    }

    /**
     * @param  array{mother: ?FamilyMember, father: ?FamilyMember, spouse: ?FamilyMember}  $anchors
     */
    private function scoreSpouseInLawMatch(FamilyMember $match, array $anchors, string $slot): array
    {
        $spouse = $anchors['spouse'];
        if (! $spouse) {
            return [
                'fits' => false,
                'score' => 0.0,
                'connection_path' => 'Enter your spouse name to verify this in-law match.',
                'suggested_join_code' => null,
            ];
        }

        $expectedSide = $slot === 'spouse_father' ? 'father' : 'mother';
        $inLawParent = $this->pickParent($spouse, $expectedSide);

        if (! $inLawParent || $inLawParent->uuid !== $match->uuid) {
            return [
                'fits' => false,
                'score' => 0.0,
                'connection_path' => "This person is not the {$expectedSide} of your spouse ({$this->fullName($spouse)}).",
                'suggested_join_code' => null,
            ];
        }

        return [
            'fits' => true,
            'score' => 1.0,
            'connection_path' => "Matches your spouse's {$expectedSide} in this tree.",
            'suggested_join_code' => $slot === 'spouse_father' ? 'spouse_father' : 'spouse_mother',
        ];
    }

    /**
     * @param  array{mother: ?FamilyMember, father: ?FamilyMember, spouse: ?FamilyMember}  $anchors
     */
    private function scoreChildMatch(FamilyMember $match, array $anchors): array
    {
        $mother = $anchors['mother'];
        $father = $anchors['father'];

        if (! $mother && ! $father) {
            return [
                'fits' => false,
                'score' => 0.0,
                'connection_path' => 'Enter your mother and father to verify this child.',
                'suggested_join_code' => null,
            ];
        }

        $fromMother = $mother && $this->sharesParentLine($match, $mother);
        $fromFather = $father && $this->sharesParentLine($match, $father);

        if ($fromMother && $fromFather) {
            return [
                'fits' => true,
                'score' => 1.0,
                'connection_path' => 'Child of both your mother and father in this tree.',
                'suggested_join_code' => 'child',
            ];
        }

        if ($fromMother || $fromFather) {
            $side = $fromMother ? 'mother' : 'father';

            return [
                'fits' => true,
                'score' => 0.85,
                'connection_path' => "Child linked to your {$side} in this tree.",
                'suggested_join_code' => 'child',
            ];
        }

        return [
            'fits' => false,
            'score' => 0.0,
            'connection_path' => 'This person is not listed as your child through the parents you entered.',
            'suggested_join_code' => null,
        ];
    }

    /**
     * @param  array{mother: ?FamilyMember, father: ?FamilyMember, spouse: ?FamilyMember}  $anchors
     */
    private function scoreExtendedKinMatch(FamilyMember $match, array $anchors, string $slot): array
    {
        $mother = $anchors['mother'];
        $father = $anchors['father'];

        if (! $mother && ! $father) {
            return [
                'fits' => false,
                'score' => 0.0,
                'connection_path' => 'Enter your mother and father to find the correct '.$slot.' line.',
                'suggested_join_code' => null,
            ];
        }

        $matchFather = $this->pickParent($match, 'father');
        $matchMother = $this->pickParent($match, 'mother');
        $candidates = [];

        if ($mother && $matchFather && $this->areSiblings($mother, $matchFather)) {
            $candidates[] = [
                'score' => 1.0,
                'path' => "Mother's side: your mother {$this->fullName($mother)} is sibling of this person's father {$this->fullName($matchFather)}.",
                'code' => $slot === 'uncle' ? 'uncle_mother_brother' : ($slot === 'aunt' ? 'aunt_mother_sister' : 'cousin_mother_brother_child'),
            ];
        }

        if ($mother && $matchMother && $this->areSiblings($mother, $matchMother)) {
            $candidates[] = [
                'score' => 0.95,
                'path' => "Mother's side: your mother is sibling of this person's mother.",
                'code' => $slot === 'cousin' ? 'cousin_mother_sister_child' : 'aunt_mother_sister',
            ];
        }

        if ($father && $matchFather && $this->areSiblings($father, $matchFather)) {
            $candidates[] = [
                'score' => 0.9,
                'path' => "Father's side: your father {$this->fullName($father)} is sibling of this person's father.",
                'code' => $slot === 'uncle' ? 'uncle_father_brother' : ($slot === 'cousin' ? 'cousin_father_brother_child' : 'aunt_father_sister'),
            ];
        }

        if ($father && $matchMother && $this->areSiblings($father, $matchMother)) {
            $candidates[] = [
                'score' => 0.85,
                'path' => "Father's side: your father is sibling of this person's mother.",
                'code' => 'cousin_father_sister_child',
            ];
        }

        if ($slot === 'uncle' && $mother && $this->areSiblings($mother, $match)) {
            $candidates[] = ['score' => 0.92, 'path' => "Mother's brother: sibling of your mother.", 'code' => 'uncle_mother_brother'];
        }

        if ($slot === 'aunt' && $mother && $this->areSiblings($mother, $match)) {
            $candidates[] = ['score' => 0.92, 'path' => "Mother's sister: sibling of your mother.", 'code' => 'aunt_mother_sister'];
        }

        if ($slot === 'uncle' && $father && $this->areSiblings($father, $match)) {
            $candidates[] = ['score' => 0.92, 'path' => "Father's brother: sibling of your father.", 'code' => 'uncle_father_brother'];
        }

        if ($slot === 'aunt' && $father && $this->areSiblings($father, $match)) {
            $candidates[] = ['score' => 0.92, 'path' => "Father's sister: sibling of your father.", 'code' => 'aunt_father_sister'];
        }

        if ($candidates === []) {
            $hint = $matchFather
                ? "This person's father is {$this->fullName($matchFather)}. Your parent should be a sibling on the correct side."
                : 'Could not verify this '.$slot.' line with the parents you entered.';

            return ['fits' => false, 'score' => 0.0, 'connection_path' => $hint, 'suggested_join_code' => null];
        }

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);
        $best = $candidates[0];

        return [
            'fits' => true,
            'score' => $best['score'],
            'connection_path' => $best['path'],
            'suggested_join_code' => $best['code'],
        ];
    }

    /**
     * @param  array{mother: ?FamilyMember, father: ?FamilyMember, spouse: ?FamilyMember}  $anchors
     */
    private function scoreGrandparentMatch(
        FamilyMember $match,
        array $anchors,
        string $side,
        string $searchSlot,
    ): array {
        $viaMother = $side === 'maternal';
        $lineParent = $viaMother ? $anchors['mother'] : $anchors['father'];

        if (! $lineParent) {
            return [
                'fits' => false,
                'score' => 0.0,
                'connection_path' => 'Enter your '.($viaMother ? 'mother' : 'father').' to verify this grandparent.',
                'suggested_join_code' => null,
            ];
        }

        foreach ($this->graph->parentsOf($lineParent->uuid) as $gp) {
            if ($gp->uuid === $match->uuid) {
                $code = match ($searchSlot) {
                    'paternal_grandfather' => 'grandfather_paternal',
                    'paternal_grandmother' => 'grandmother_paternal',
                    'maternal_grandfather' => 'grandfather_maternal',
                    'maternal_grandmother' => 'grandmother_maternal',
                    default => $viaMother ? 'grandfather_maternal' : 'grandfather_paternal',
                };

                return [
                    'fits' => true,
                    'score' => 1.0,
                    'connection_path' => ($viaMother ? "Mother's" : "Father's").' side grandparent verified in this tree.',
                    'suggested_join_code' => $code,
                ];
            }
        }

        return [
            'fits' => false,
            'score' => 0.0,
            'connection_path' => 'This person is not a grandparent on the '.$side.' line for the parent you entered.',
            'suggested_join_code' => null,
        ];
    }

    /**
     * @param  array{mother: ?FamilyMember, father: ?FamilyMember, spouse: ?FamilyMember}  $anchors
     */
    private function scoreBestKinMatch(FamilyMember $match, array $anchors): array
    {
        $scorers = [
            $this->scoreSiblingMatch($match, $anchors),
            $this->scoreExtendedKinMatch($match, $anchors, 'cousin'),
            $this->scoreChildMatch($match, $anchors),
        ];

        usort($scorers, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

        foreach ($scorers as $result) {
            if ($result['fits']) {
                return $result;
            }
        }

        return $scorers[0];
    }

    private function sharesParentLine(FamilyMember $child, FamilyMember $parent): bool
    {
        return collect($this->graph->parentsOf($child->uuid))
            ->contains(fn (FamilyMember $p) => $p->uuid === $parent->uuid);
    }

    private function areSiblings(FamilyMember $a, FamilyMember $b): bool
    {
        if ($a->uuid === $b->uuid) {
            return false;
        }

        $parentsA = collect($this->graph->parentsOf($a->uuid))->pluck('uuid');
        $parentsB = collect($this->graph->parentsOf($b->uuid))->pluck('uuid');

        return $parentsA->isNotEmpty() && $parentsA->intersect($parentsB)->isNotEmpty();
    }

    private function areSpouses(FamilyMember $a, FamilyMember $b): bool
    {
        return RelationshipEdge::query()
            ->where(function ($query) use ($a, $b) {
                $query->where(function ($q) use ($a, $b) {
                    $q->where('from_member_uuid', $a->uuid)->where('to_member_uuid', $b->uuid);
                })->orWhere(function ($q) use ($a, $b) {
                    $q->where('from_member_uuid', $b->uuid)->where('to_member_uuid', $a->uuid);
                });
            })
            ->whereHas('edgeType', fn ($q) => $q->where('code', 'spouse_of'))
            ->exists();
    }

    private function pickParent(FamilyMember $child, string $side): ?FamilyMember
    {
        $parents = $this->graph->parentsOf($child->uuid);

        return $parents[$side]
            ?? collect($parents)->first(
                fn (FamilyMember $p) => $p->gender === ($side === 'father' ? 'male' : 'female'),
            );
    }

    /** @param  array<string, mixed>|null  $data */
    private function findInFamily(FamilyMember $anchor, ?array $data): ?FamilyMember
    {
        if ($data === null) {
            return null;
        }

        $first = trim((string) ($data['first_name'] ?? ''));
        $last = trim((string) ($data['last_name'] ?? ''));

        if ($first === '' && $last === '') {
            return null;
        }

        return FamilyMember::query()
            ->where('family_uuid', $anchor->family_uuid)
            ->where(function ($query) use ($first, $last) {
                if ($first !== '') {
                    $query->where('first_name', $first);
                }
                if ($last !== '') {
                    $query->where('last_name', $last);
                }
            })
            ->first();
    }

    private function fullName(FamilyMember $member): string
    {
        return trim($member->first_name.' '.$member->last_name);
    }

    private function joinCodesCompatible(string $selected, ?string $suggested): bool
    {
        if ($suggested === null || $suggested === $selected) {
            return true;
        }

        if ($selected === 'sibling' && in_array($suggested, ['sibling', 'half_sibling_mother', 'half_sibling_father'], true)) {
            return true;
        }

        if (str_starts_with($selected, 'cousin_') && str_starts_with($suggested, 'cousin_')) {
            return $selected === $suggested;
        }

        return false;
    }
}

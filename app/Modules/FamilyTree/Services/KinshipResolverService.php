<?php

namespace App\Modules\FamilyTree\Services;

use App\Modules\FamilyTree\Enums\TreeViewMode;
use Illuminate\Support\Collection;

class KinshipResolverService
{
    private const MAX_PATH_DEPTH = 10;

    /**
     * @return array<string, list<array{to: string, step: string}>>
     */
    public function buildAdjacency(Collection $edges, TreeViewMode $mode): array
    {
        $adjacency = [];

        foreach ($edges as $edge) {
            $code = $edge->edgeType->code;

            if ($this->isParentEdge($code)) {
                $this->pushStep($adjacency, $edge->to_member_uuid, $edge->from_member_uuid, 'U');
                $this->pushStep($adjacency, $edge->from_member_uuid, $edge->to_member_uuid, 'D');

                continue;
            }

            if ($code === 'spouse_of' && $mode !== TreeViewMode::Blood) {
                $this->pushStep($adjacency, $edge->from_member_uuid, $edge->to_member_uuid, 'S');
                $this->pushStep($adjacency, $edge->to_member_uuid, $edge->from_member_uuid, 'S');
            }
        }

        return $adjacency;
    }

    /** @return list<string>|null */
    public function findPath(string $fromMemberUuid, string $toMemberUuid, array $adjacency): ?array
    {
        if ($fromMemberUuid === $toMemberUuid) {
            return [];
        }

        $queue = [[$fromMemberUuid, []]];
        $visited = [$fromMemberUuid => true];

        while ($queue !== []) {
            [$current, $path] = array_shift($queue);

            if (count($path) >= self::MAX_PATH_DEPTH) {
                continue;
            }

            foreach ($adjacency[$current] ?? [] as $step) {
                $nextPath = [...$path, $step['step']];

                if ($step['to'] === $toMemberUuid) {
                    return $nextPath;
                }

                if (! isset($visited[$step['to']])) {
                    $visited[$step['to']] = true;
                    $queue[] = [$step['to'], $nextPath];
                }
            }
        }

        return null;
    }

    public function labelFromPath(?array $path, string $gender): string
    {
        if ($path === null) {
            return 'Relative';
        }

        if ($path === []) {
            return 'Self';
        }

        $key = implode('', $path);

        $labels = [
            'U' => ['male' => 'Father', 'female' => 'Mother', 'other' => 'Parent', 'unknown' => 'Parent'],
            'D' => ['male' => 'Son', 'female' => 'Daughter', 'other' => 'Child', 'unknown' => 'Child'],
            'UU' => ['male' => 'Grandfather', 'female' => 'Grandmother', 'other' => 'Grandparent', 'unknown' => 'Grandparent'],
            'DD' => ['male' => 'Grandson', 'female' => 'Granddaughter', 'other' => 'Grandchild', 'unknown' => 'Grandchild'],
            'UD' => ['male' => 'Brother', 'female' => 'Sister', 'other' => 'Sibling', 'unknown' => 'Sibling'],
            'S' => ['male' => 'Husband', 'female' => 'Wife', 'other' => 'Spouse', 'unknown' => 'Spouse'],
            'SU' => ['male' => 'Father-in-law', 'female' => 'Mother-in-law', 'other' => 'Parent-in-law', 'unknown' => 'Parent-in-law'],
            'US' => ['male' => 'Son-in-law', 'female' => 'Daughter-in-law', 'other' => 'Child-in-law', 'unknown' => 'Child-in-law'],
            'DS' => ['male' => 'Son-in-law', 'female' => 'Daughter-in-law', 'other' => 'Child-in-law', 'unknown' => 'Child-in-law'],
            'UUD' => ['male' => 'Uncle', 'female' => 'Aunt', 'other' => 'Aunt/Uncle', 'unknown' => 'Aunt/Uncle'],
            'UDD' => ['male' => 'Nephew', 'female' => 'Niece', 'other' => 'Niece/Nephew', 'unknown' => 'Niece/Nephew'],
            'UUDD' => ['male' => 'Cousin', 'female' => 'Cousin', 'other' => 'Cousin', 'unknown' => 'Cousin'],
            'UUUD' => ['male' => 'Great-uncle', 'female' => 'Great-aunt', 'other' => 'Great-aunt/uncle', 'unknown' => 'Great-aunt/uncle'],
            'UUDDD' => ['male' => 'First cousin once removed', 'female' => 'First cousin once removed', 'other' => 'First cousin once removed', 'unknown' => 'First cousin once removed'],
        ];

        if (isset($labels[$key])) {
            return $labels[$key][$gender] ?? $labels[$key]['unknown'];
        }

        if (str_starts_with($key, 'U') && ! str_contains($key, 'S') && ! str_contains($key, 'D')) {
            $generations = strlen($key);

            return match (true) {
                $generations >= 3 => $this->genderLabel($gender, 'Great-grandparent', 'Great-grandparent', 'Great-grandparent'),
                default => $this->genderLabel($gender, 'Grandfather', 'Grandmother', 'Grandparent'),
            };
        }

        if (str_contains($key, 'S')) {
            return 'In-law';
        }

        return 'Relative';
    }

    private function isParentEdge(string $code): bool
    {
        return str_ends_with($code, '_parent_of') || $code === 'parent_of';
    }

    /** @param array<string, list<array{to: string, step: string}>> $adjacency */
    private function pushStep(array &$adjacency, string $from, string $to, string $step): void
    {
        $adjacency[$from][] = ['to' => $to, 'step' => $step];
    }

    private function genderLabel(string $gender, string $male, string $female, string $fallback): string
    {
        return match ($gender) {
            'male' => $male,
            'female' => $female,
            default => $fallback,
        };
    }
}

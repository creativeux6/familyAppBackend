<?php

namespace App\Modules\FamilyTree\Services;

class JoinRelationOptionsResolver
{
    /** @var list<string> */
    private const CORE_RELATIONS = [
        'self', 'father', 'mother', 'child', 'sibling', 'spouse',
        'spouse_father', 'spouse_mother',
    ];

    /**
     * @return list<array{code: string, label: string, group: string}>
     */
    public function optionsForSearchSlot(?string $searchSlot): array
    {
        if ($searchSlot === null || $searchSlot === '') {
            return $this->coreOptions();
        }

        return match ($searchSlot) {
            'cousin' => $this->cousinOptions(),
            'uncle' => $this->uncleOptions(),
            'aunt' => $this->auntOptions(),
            'paternal_grandfather', 'paternal_grandmother',
            'maternal_grandfather', 'maternal_grandmother' => $this->grandparentOptions($searchSlot),
            'father' => $this->labeledOptions([
                ['father', 'He is my father'],
                ['step_father', 'He is my stepfather'],
            ], 'Father'),
            'mother' => $this->labeledOptions([
                ['mother', 'She is my mother'],
                ['step_mother', 'She is my stepmother'],
            ], 'Mother'),
            'sibling' => $this->labeledOptions([
                ['sibling', 'Full brother or sister (same mother and father)'],
                ['half_sibling_mother', "Half-brother/sister — mother's side only"],
                ['half_sibling_father', "Half-brother/sister — father's side only"],
            ], 'Sibling'),
            'spouse' => $this->labeledOptions([
                ['spouse', 'He/she is my spouse'],
            ], 'Spouse'),
            'child' => $this->labeledOptions([
                ['child', 'He/she is my child'],
            ], 'Child'),
            'spouse_father' => $this->labeledOptions([
                ['spouse_father', "He is my spouse's father"],
            ], 'In-law'),
            'spouse_mother' => $this->labeledOptions([
                ['spouse_mother', "She is my spouse's mother"],
            ], 'In-law'),
            'other_relative' => $this->broadOptions(),
            default => $this->coreOptions(),
        };
    }

    /** @return list<string> */
    public function allAllowedCodes(): array
    {
        $codes = self::CORE_RELATIONS;

        foreach ([
            $this->cousinOptions(),
            $this->uncleOptions(),
            $this->auntOptions(),
            $this->grandparentOptions('paternal_grandfather'),
            $this->grandparentOptions('maternal_grandmother'),
            $this->labeledOptions([
                ['step_father', ''],
                ['step_mother', ''],
                ['half_sibling_mother', ''],
                ['half_sibling_father', ''],
            ], ''),
        ] as $options) {
            foreach ($options as $option) {
                $codes[] = $option['code'];
            }
        }

        return array_values(array_unique($codes));
    }

    public function labelFor(string $code): string
    {
        foreach ($this->broadOptions() as $option) {
            if ($option['code'] === $code) {
                return $option['label'];
            }
        }

        return str_replace('_', ' ', $code);
    }

    /**
     * @return list<string>
     */
    public function requiredParentsForRelation(string $code): array
    {
        return app(FamilyRelationPathService::class)->requiredAnchorsForJoinCode($code);
    }

    /** @return list<string> */
    public function requiredParentsForSearch(array $answers): array
    {
        return app(FamilyRelationPathService::class)->requiredAnchorsForSearch($answers);
    }

    /**
     * @param  list<array{code: string, label: string, group: string}>  $options
     * @return list<array{code: string, label: string, group: string, requires_parents: list<string>}>
     */
    public function enrichOptions(array $options): array
    {
        return array_map(
            fn (array $option) => [
                ...$option,
                'requires_parents' => $this->requiredParentsForRelation($option['code']),
            ],
            $options,
        );
    }

    /**
     * @return list<array{code: string, label: string, group: string}>
     */
    private function coreOptions(): array
    {
        return $this->labeledOptions([
            ['father', 'He is my father'],
            ['mother', 'She is my mother'],
            ['sibling', 'He/she is my brother or sister'],
            ['spouse', 'He/she is my spouse'],
            ['child', 'He/she is my child'],
            ['spouse_father', "He is my spouse's father"],
            ['spouse_mother', "She is my spouse's mother"],
            ['self', 'This person is me'],
        ], 'Direct');
    }

    /**
     * @return list<array{code: string, label: string, group: string}>
     */
    private function cousinOptions(): array
    {
        return $this->labeledOptions([
            ['cousin_mother_brother_child', "Mother's brother's child"],
            ['cousin_mother_sister_child', "Mother's sister's child"],
            ['cousin_father_brother_child', "Father's brother's child"],
            ['cousin_father_sister_child', "Father's sister's child"],
        ], 'Cousin');
    }

    /**
     * @return list<array{code: string, label: string, group: string}>
     */
    private function uncleOptions(): array
    {
        return $this->labeledOptions([
            ['uncle_mother_brother', "Mother's brother"],
            ['uncle_mother_sister_husband', "Mother's sister's husband"],
            ['uncle_father_brother', "Father's brother"],
            ['uncle_father_sister_husband', "Father's sister's husband"],
        ], 'Uncle');
    }

    /**
     * @return list<array{code: string, label: string, group: string}>
     */
    private function auntOptions(): array
    {
        return $this->labeledOptions([
            ['aunt_mother_sister', "Mother's sister"],
            ['aunt_mother_brother_wife', "Mother's brother's wife"],
            ['aunt_father_sister', "Father's sister"],
            ['aunt_father_brother_wife', "Father's brother's wife"],
        ], 'Aunt');
    }

    /**
     * @return list<array{code: string, label: string, group: string}>
     */
    private function grandparentOptions(string $slot): array
    {
        $all = $this->labeledOptions([
            ['grandfather_paternal', 'My paternal grandfather'],
            ['grandmother_paternal', 'My paternal grandmother'],
            ['grandfather_maternal', 'My maternal grandfather'],
            ['grandmother_maternal', 'My maternal grandmother'],
        ], 'Grandparent');

        if (in_array($slot, ['paternal_grandfather', 'paternal_grandmother', 'maternal_grandfather', 'maternal_grandmother'], true)) {
            $preferred = match ($slot) {
                'paternal_grandfather' => 'grandfather_paternal',
                'paternal_grandmother' => 'grandmother_paternal',
                'maternal_grandfather' => 'grandfather_maternal',
                'maternal_grandmother' => 'grandmother_maternal',
            };

            usort($all, fn (array $a, array $b) => ($b['code'] === $preferred ? 1 : 0) <=> ($a['code'] === $preferred ? 1 : 0));
        }

        return $all;
    }

    /**
     * @return list<array{code: string, label: string, group: string}>
     */
    private function broadOptions(): array
    {
        return [
            ...$this->coreOptions(),
            ...$this->cousinOptions(),
            ...$this->uncleOptions(),
            ...$this->auntOptions(),
            ...$this->grandparentOptions('paternal_grandfather'),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     * @return list<array{code: string, label: string, group: string}>
     */
    private function labeledOptions(array $pairs, string $group): array
    {
        return array_map(
            fn (array $pair) => ['code' => $pair[0], 'label' => $pair[1], 'group' => $group],
            $pairs,
        );
    }
}

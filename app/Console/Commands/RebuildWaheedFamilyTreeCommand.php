<?php

namespace App\Console\Commands;

use App\Models\FamilyMember;
use App\Models\User;
use App\Modules\FamilyTree\Services\FamilyMemberGraphService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Full rebuild of Abdul Waheed's tree from the known pre-wipe structure.
 */
class RebuildWaheedFamilyTreeCommand extends Command
{
    protected $signature = 'family-tree:rebuild-waheed-tree
                            {--phone=+923087119409 : Account phone}
                            {--dry-run : Print plan only}';

    protected $description = 'Rebuild Abdul Waheed family edges from the known complete relative list.';

    private FamilyMemberGraphService $graph;

    private FamilyMember $self;

    private int $userId;

    private bool $dryRun = false;

    private int $created = 0;

    private int $linked = 0;

    public function handle(FamilyMemberGraphService $graph): int
    {
        $this->graph = $graph;
        $this->dryRun = (bool) $this->option('dry-run');

        $phone = (string) $this->option('phone');
        $user = User::query()->where('phone', $phone)->first();
        if (! $user) {
            $this->error("User not found for phone {$phone}");

            return self::FAILURE;
        }

        $self = FamilyMember::query()->where('user_id', $user->id)->first();
        if (! $self) {
            $this->error('No family member for user');

            return self::FAILURE;
        }

        $this->self = $self;
        $this->userId = (int) $user->id;

        $this->info("Rebuilding tree for {$user->display_name} ({$phone}) family={$self->family_uuid}");
        if ($this->dryRun) {
            $this->warn('Dry run — no writes');
        }

        $run = function () {
            // Core nuclear family
            $father = $this->person('Khurshid', 'Alam', 'male');
            $mother = $this->person('Nazira', 'Bibi', 'female');
            $this->parent($father, $this->self);
            $this->parent($mother, $this->self);

            $spouse = $this->person('Ambreen', 'Younas', 'female');
            $this->spouse($this->self, $spouse);

            $child = $this->person('Muhammad', 'Ayan', 'male');
            $this->parent($this->self, $child);
            $this->parent($spouse, $child);

            // Waheed siblings (same parents)
            foreach ([
                ['Sehar', 'Khurshid', 'female'],
                ['Sidra', 'Khurshid', 'female'],
                ['Simra', 'Khurshid', 'female'],
            ] as [$first, $last, $gender]) {
                $sib = $this->person($first, $last, $gender);
                $this->parent($father, $sib);
                $this->parent($mother, $sib);
            }
            // Adeel may be stored as "Muhammad Adeel" or "Adeel"
            $adeel = $this->findPerson('Muhammad', 'Adeel', 'male')
                ?? $this->findPerson('Adeel', 'Khurshid', 'male')
                ?? $this->person('Muhammad', 'Adeel', 'male');
            $this->parent($father, $adeel);
            $this->parent($mother, $adeel);

            // Paternal grandparents (Khurshid's parents)
            $pgf = $this->person('Ilam Din', 'Sabar', 'male');
            $pgm = $this->person('Sharifa', 'Bibi', 'female');
            $this->parent($pgf, $father);
            $this->parent($pgm, $father);

            // Maternal grandparents (Nazira's parents = Khurshid's in-laws)
            $mgf = $this->person('Roshan', 'Din', 'male');
            $mgm = $this->person('Hamida', 'Bibi', 'female');
            $this->parent($mgf, $mother);
            $this->parent($mgm, $mother);

            // Father's siblings (paternal uncles/aunts) — share paternal grandparents
            $uncleHussain = $this->person('Muhammad', 'Hussain', 'male');
            $auntAsgree = $this->person('Asgree', 'Bibi', 'female');
            $uncleAli = $this->person('Ali', 'Hussain', 'male');
            $auntSaba = $this->person('Saba', 'Younas', 'female');

            foreach ([$uncleHussain, $auntAsgree, $uncleAli, $auntSaba] as $sib) {
                $this->parent($pgf, $sib);
                $this->parent($pgm, $sib);
            }

            // IMPORTANT: Asgree Bibi has NO children recorded in this tree.
            // Noman Zahid (father: Zahid Akhtar) is an independent family —
            // never attach him or his household under Asgree / Khurshid / Waheed.
            // Detach any leftover wrong edges from older rebuilds.
            foreach (['Noman Zahid', 'Zahid'] as $wrongLabel) {
                $parts = explode(' ', $wrongLabel, 2);
                $wrong = $this->findPerson($parts[0], $parts[1] ?? '', 'male')
                    ?? ($parts[0] === 'Zahid' ? $this->findPerson('Zahid', '', 'male') : null);
                if ($wrong) {
                    $this->detachWrongChildren($auntAsgree, [$wrong]);
                }
            }

            // Saba Younas (paternal aunt) + husband Muhammad Younas + daughters
            $sabaHusband = $this->person('Muhammad', 'Younas', 'male');
            $this->spouse($auntSaba, $sabaHusband);
            // Ambreen already linked as Waheed's wife; also daughter of Saba
            $this->parent($auntSaba, $spouse);
            $this->parent($sabaHusband, $spouse);
            foreach ([['Sumreen', 'Younas', 'female'], ['Muneeba', 'Younas', 'female']] as [$first, $last, $gender]) {
                $d = $this->person($first, $last, $gender);
                $this->parent($auntSaba, $d);
                $this->parent($sabaHusband, $d);
            }

            // Afnan Ul Haq ← Ehsan Ul Haq (keep in family if present / create)
            $ehsan = $this->person('Ehsan Ul', 'Haq', 'male');
            $afnan = $this->person('Afnan Ul', 'Haq', 'male');
            $this->parent($ehsan, $afnan);

            // Remove bad stub grandparents + wrong-generation edges
            // (siblings of Waheed must not be children of Ilam Din / Sharifa).
            $selfSiblings = [$adeel];
            foreach (['Sehar', 'Sidra', 'Simra'] as $sibFirst) {
                $s = $this->findPerson($sibFirst, 'Khurshid', null);
                if ($s) {
                    $selfSiblings[] = $s;
                }
            }
            $this->purgeStubGrandparents();
            $this->detachWrongChildren($pgf, array_merge([$this->self, $mother], $selfSiblings));
            $this->detachWrongChildren($pgm, array_merge([$this->self, $mother], $selfSiblings));
            $this->detachWrongChildren($mgf, array_merge([$this->self, $father], $selfSiblings));
            $this->detachWrongChildren($mgm, array_merge([$this->self, $father], $selfSiblings));

            // Force correct genders for slot detection
            if (! $this->dryRun) {
                $pgf->update(['gender' => 'male', 'first_name' => 'Ilam Din', 'last_name' => 'Sabar']);
                $pgm->update(['gender' => 'female', 'first_name' => 'Sharifa', 'last_name' => 'Bibi']);
                $mgf->update(['gender' => 'male', 'first_name' => 'Roshan', 'last_name' => 'Din']);
                $mgm->update(['gender' => 'female', 'first_name' => 'Hamida', 'last_name' => 'Bibi']);
            }
        };

        if ($this->dryRun) {
            $run();
        } else {
            DB::transaction($run);
        }

        $info = $this->graph->familyInfoForMember($this->self->fresh(), $user);
        $this->newLine();
        $this->info("created={$this->created} linked={$this->linked}");
        $this->line(json_encode([
            'father' => $info['father']['first_name'] ?? null,
            'mother' => $info['mother']['first_name'] ?? null,
            'paternal_grandfather' => $info['paternal_grandfather']['first_name'] ?? null,
            'paternal_grandmother' => $info['paternal_grandmother']['first_name'] ?? null,
            'maternal_grandfather' => $info['maternal_grandfather']['first_name'] ?? null,
            'maternal_grandmother' => $info['maternal_grandmother']['first_name'] ?? null,
            'siblings' => array_map(fn ($s) => trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? '')), $info['siblings'] ?? []),
            'paternal_uncles' => array_map(fn ($s) => trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? '')), $info['paternal_uncles'] ?? []),
            'paternal_aunts' => array_map(fn ($s) => trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? '')), $info['paternal_aunts'] ?? []),
            'spouse' => $info['spouses'][0]['first_name'] ?? null,
            'spouse_father' => $info['spouses'][0]['father']['first_name'] ?? null,
            'spouse_mother' => $info['spouses'][0]['mother']['first_name'] ?? null,
            'children' => array_map(fn ($s) => trim(($s['first_name'] ?? '').' '.($s['last_name'] ?? '')), $info['children'] ?? []),
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function person(string $first, string $last, string $gender): FamilyMember
    {
        $existing = $this->findPerson($first, $last, $gender);
        if ($existing) {
            if (! $this->dryRun) {
                $existing->update([
                    'first_name' => $first,
                    'last_name' => $last !== '' ? $last : $existing->last_name,
                    'gender' => $gender,
                ]);
            }

            return $existing->fresh() ?? $existing;
        }

        $this->created++;
        $this->line("  create {$first} {$last}");
        if ($this->dryRun) {
            $stub = new FamilyMember([
                'uuid' => (string) Str::uuid(),
                'family_uuid' => $this->self->family_uuid,
                'first_name' => $first,
                'last_name' => $last,
                'gender' => $gender,
                'is_living' => true,
            ]);
            $stub->uuid = (string) Str::uuid();

            return $stub;
        }

        return FamilyMember::create([
            'uuid' => (string) Str::uuid(),
            'family_uuid' => $this->self->family_uuid,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => $gender,
            'is_living' => true,
            'user_id' => null,
            'match_confidence' => 0,
        ]);
    }

    private function findPerson(string $first, string $last, ?string $gender = null): ?FamilyMember
    {
        $first = trim($first);
        $last = trim($last);

        $base = FamilyMember::query()->where('family_uuid', $this->self->family_uuid);

        // 1) Exact first + last
        $exact = (clone $base)
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower($first)])
            ->when($last !== '', fn ($q) => $q->whereRaw('LOWER(TRIM(last_name)) = ?', [mb_strtolower($last)]))
            ->first();
        if ($exact) {
            return $exact;
        }

        // 2) Compound first names: "Ilam Din" matches stored "Ilam" with same last name only
        if (str_contains($first, ' ') && $last !== '') {
            $token = explode(' ', $first)[0];

            return (clone $base)
                ->whereRaw('LOWER(TRIM(last_name)) = ?', [mb_strtolower($last)])
                ->where(function ($q) use ($first, $token) {
                    $q->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower($first)])
                        ->orWhereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower($token)])
                        ->orWhereRaw('LOWER(first_name) LIKE ?', [mb_strtolower($first).'%']);
                })
                ->first();
        }

        // 3) Last name empty: exact first name only (e.g. Zahid)
        if ($last === '') {
            return (clone $base)
                ->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower($first)])
                ->first();
        }

        return null;
    }

    private function parent(FamilyMember $parent, FamilyMember $child): void
    {
        $this->linked++;
        $this->line("  parent {$parent->first_name} → {$child->first_name}");
        if (! $this->dryRun) {
            $this->graph->ensureParentEdge($parent, $child, $this->userId);
        }
    }

    private function spouse(FamilyMember $a, FamilyMember $b): void
    {
        $this->linked++;
        $this->line("  spouse {$a->first_name} ↔ {$b->first_name}");
        if (! $this->dryRun) {
            $this->graph->ensureSpouseEdge($a, $b, $this->userId);
        }
    }

    private function purgeStubGrandparents(): void
    {
        $stubs = FamilyMember::query()
            ->where('family_uuid', $this->self->family_uuid)
            ->where(function ($q) {
                $q->whereRaw('LOWER(first_name) IN (?, ?)', ['grandfather', 'grandmother'])
                    ->orWhereRaw('LOWER(TRIM(first_name)) LIKE ?', ['grandfather%'])
                    ->orWhereRaw('LOWER(TRIM(first_name)) LIKE ?', ['grandmother%']);
            })
            ->get();

        foreach ($stubs as $stub) {
            $this->line("  purge stub {$stub->first_name} {$stub->last_name}");
            if (! $this->dryRun) {
                $this->graph->deleteMemberGraph($stub);
            }
        }
    }

    /**
     * @param  list<FamilyMember>  $children
     */
    private function detachWrongChildren(FamilyMember $parent, array $children): void
    {
        foreach ($children as $child) {
            if (! $child instanceof FamilyMember) {
                continue;
            }
            $this->line("  detach wrong edge {$parent->first_name} → {$child->first_name}");
            if ($this->dryRun) {
                continue;
            }
            \App\Models\RelationshipEdge::query()
                ->where('from_member_uuid', $parent->uuid)
                ->where('to_member_uuid', $child->uuid)
                ->whereHas('edgeType', fn ($q) => $q->whereIn('code', [
                    'parent_of', 'adoptive_parent_of', 'step_parent_of',
                ]))
                ->delete();
        }
    }
}

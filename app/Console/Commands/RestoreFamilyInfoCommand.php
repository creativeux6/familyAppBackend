<?php

namespace App\Console\Commands;

use App\Models\FamilyMember;
use App\Models\User;
use App\Models\UserDeclaredRelative;
use App\Modules\FamilyTree\Services\FamilyTreeService;
use Illuminate\Console\Command;

/**
 * Rebuild graph links after a destructive partial family-info PATCH.
 *
 * Sources (first match wins):
 *  1. --file=snapshot.json with a family_info object
 *  2. user_declared_relatives rows for the user
 */
class RestoreFamilyInfoCommand extends Command
{
    protected $signature = 'family-tree:restore-family-info
                            {--phone= : User phone, e.g. +923087119409}
                            {--user= : User id}
                            {--preset= : Built-in snapshot name, e.g. waheed}
                            {--file= : JSON snapshot with family_info (and optional declared)}
                            {--strip-uuids : Always drop relative uuids (force recreate by name)}
                            {--dry-run : Show the payload without writing}';

    protected $description = 'Restore mother/spouse/children/siblings links from a preset, snapshot file, or declared relatives.';

    public function handle(FamilyTreeService $familyTree): int
    {
        $user = $this->resolveUser();
        if (! $user) {
            $this->error('User not found. Pass --phone= or --user=.');

            return self::FAILURE;
        }

        $self = FamilyMember::query()->where('user_id', $user->id)->first();
        if (! $self) {
            $this->error("User {$user->id} has no family member row.");

            return self::FAILURE;
        }

        $payload = $this->resolvePayload($user);
        if ($payload === []) {
            $this->error('No restore payload. Use --preset=waheed, --file=..., or ensure declared relatives exist.');

            return self::FAILURE;
        }

        // Keep uuids that still exist in DB so surviving stubs are re-linked;
        // drop missing ones so names recreate cleanly without duplicate errors.
        $payload = $this->option('strip-uuids')
            ? $this->stripRelativeUuids($payload)
            : $this->pruneMissingUuids($payload);

        $this->info("Restoring family links for {$user->display_name} ({$user->phone}) / member {$self->uuid}");
        $this->line(json_encode([
            'father' => $payload['father']['first_name'] ?? null,
            'mother' => $payload['mother']['first_name'] ?? null,
            'spouses' => array_map(
                static fn (array $row) => $row['first_name'] ?? null,
                $payload['spouses'] ?? [],
            ),
            'children' => array_map(
                static fn (array $row) => $row['first_name'] ?? null,
                $payload['children'] ?? [],
            ),
            'siblings' => array_map(
                static fn (array $row) => $row['first_name'] ?? null,
                $payload['siblings'] ?? [],
            ),
        ], JSON_PRETTY_PRINT));

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no changes written.');

            return self::SUCCESS;
        }

        $result = $familyTree->updateFamilyInfo($user, $payload);
        $info = $result['family_info'] ?? [];

        $this->info('Restored:');
        $this->line('  father: '.($info['father']['first_name'] ?? '—'));
        $this->line('  mother: '.($info['mother']['first_name'] ?? '—'));
        $this->line('  spouses: '.count($info['spouses'] ?? []));
        $this->line('  children: '.count($info['children'] ?? []));
        $this->line('  siblings: '.count($info['siblings'] ?? []));

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        if ($this->option('user')) {
            return User::query()->find((int) $this->option('user'));
        }

        $phone = trim((string) $this->option('phone'));
        if ($phone === '') {
            return null;
        }

        return User::query()->where('phone', $phone)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePayload(User $user): array
    {
        $file = trim((string) $this->option('file'));
        $preset = trim((string) $this->option('preset'));

        if ($file === '' && $preset !== '') {
            $file = database_path('recovery/'.$preset.'_family_info_snapshot.json');
        }

        if ($file !== '') {
            if (! is_file($file)) {
                $this->error("File not found: {$file}");
                $this->line('After git pull, presets live in database/recovery/');

                return [];
            }

            $decoded = json_decode((string) file_get_contents($file), true);
            if (! is_array($decoded)) {
                $this->error('Invalid JSON snapshot.');

                return [];
            }

            $info = $decoded['family_info'] ?? $decoded;
            if (! is_array($info)) {
                return [];
            }

            return $info;
        }

        return $this->payloadFromDeclared($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromDeclared(User $user): array
    {
        $rows = UserDeclaredRelative::query()
            ->where('user_id', $user->id)
            ->orderBy('relation_type')
            ->orderBy('relation_index')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $payload = [
            'spouses' => [],
            'children' => [],
            'siblings' => [],
        ];

        foreach ($rows as $row) {
            $person = [
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'gender' => $row->gender,
                'is_living' => (bool) $row->is_living,
                'date_of_birth' => $row->date_of_birth?->format('Y-m-d'),
                'date_of_death' => $row->date_of_death?->format('Y-m-d'),
            ];

            match ($row->relation_type) {
                'father' => $payload['father'] = $person,
                'mother' => $payload['mother'] = $person,
                'spouse' => $payload['spouses'][$row->relation_index] = array_merge(
                    $payload['spouses'][$row->relation_index] ?? [],
                    $person,
                ),
                'spouse_father' => $payload['spouses'][$row->relation_index]['father'] = $person,
                'spouse_mother' => $payload['spouses'][$row->relation_index]['mother'] = $person,
                'child' => $payload['children'][$row->relation_index] = $person,
                'sibling' => $payload['siblings'][$row->relation_index] = $person,
                default => null,
            };
        }

        $payload['spouses'] = array_values($payload['spouses']);
        $payload['children'] = array_values($payload['children']);
        $payload['siblings'] = array_values($payload['siblings']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripRelativeUuids(array $payload): array
    {
        $stripPerson = function ($value) use (&$stripPerson) {
            if (! is_array($value)) {
                return $value;
            }

            unset($value['uuid'], $value['member_code'], $value['member_uuid'], $value['is_registered']);

            foreach ($value as $key => $child) {
                if (is_array($child)) {
                    $value[$key] = array_is_list($child)
                        ? array_map($stripPerson, $child)
                        : $stripPerson($child);
                }
            }

            return $value;
        };

        return $stripPerson($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function pruneMissingUuids(array $payload): array
    {
        $prune = function ($value) use (&$prune) {
            if (! is_array($value)) {
                return $value;
            }

            if (isset($value['uuid']) && is_string($value['uuid']) && $value['uuid'] !== '') {
                $exists = FamilyMember::query()->where('uuid', $value['uuid'])->exists();
                if (! $exists) {
                    unset($value['uuid'], $value['member_code'], $value['member_uuid']);
                }
            }
            unset($value['is_registered']);

            foreach ($value as $key => $child) {
                if (is_array($child)) {
                    $value[$key] = array_is_list($child)
                        ? array_map($prune, $child)
                        : $prune($child);
                }
            }

            return $value;
        };

        return $prune($payload);
    }
}

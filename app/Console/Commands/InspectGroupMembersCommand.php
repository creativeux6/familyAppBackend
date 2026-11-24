<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Inspect who is in a group and what we can infer about how they got there.
 *
 * Limitation: group_members has no added_by_user_id — we never stored who
 * added each member. We can only show creator + each member's joined_at.
 */
class InspectGroupMembersCommand extends Command
{
    protected $signature = 'groups:inspect-members
                            {name=Lamp Team : Group name (partial match OK)}';

    protected $description = 'Show group creator and members with join times (to infer create vs later-add).';

    public function handle(): int
    {
        $needle = trim((string) $this->argument('name'));
        // "Lamp Team" should still match "LampTeam"
        $compact = preg_replace('/\s+/', '', $needle) ?? $needle;

        $groups = Group::query()
            ->where(function ($q) use ($needle, $compact) {
                $q->where('name', 'like', '%'.$needle.'%');
                if ($compact !== '' && strcasecmp($compact, $needle) !== 0) {
                    $q->orWhereRaw(
                        "REPLACE(name, ' ', '') LIKE ?",
                        ['%'.$compact.'%']
                    );
                }
            })
            ->with(['members.user'])
            ->get();

        if ($groups->isEmpty()) {
            $this->error("No group matching \"{$needle}\"");
            $this->line('All groups:');
            foreach (Group::query()->orderBy('name')->get(['uuid', 'name', 'type']) as $g) {
                $this->line("  - {$g->name} ({$g->type})");
            }

            return self::FAILURE;
        }

        foreach ($groups as $group) {
            $creator = User::query()->find($group->created_by_user_id);
            $this->newLine();
            $this->info("Group: {$group->name}");
            $this->line('  uuid: '.$group->uuid);
            $this->line('  type: '.($group->type ?? 'group'));
            $this->line('  created_at: '.$group->created_at);
            $this->line('  created_by: '.($creator
                ? trim(($creator->display_name ?? '').' '.$creator->phone).' (user#'.$creator->id.')'
                : 'user#'.$group->created_by_user_id));
            $this->line('  member_count: '.$group->members->count());
            $this->newLine();
            $this->line(sprintf(
                '  %-20s %-10s %-22s %-22s %-22s %s',
                'joined_at',
                'role',
                'name',
                'phone',
                'added_by',
                'inference',
            ));
            $this->line('  '.str_repeat('-', 120));

            $createdAt = $group->created_at?->copy();
            foreach ($group->members->sortBy('joined_at') as $membership) {
                $u = $membership->user;
                $joined = $membership->joined_at;
                $addedBy = $membership->added_by_user_id
                    ? User::query()->find($membership->added_by_user_id)
                    : null;
                $addedByLabel = $addedBy?->display_name ?? '—';
                $inference = 'unknown';
                if ($createdAt && $joined) {
                    $delta = abs($joined->diffInSeconds($createdAt));
                    if ($delta <= 5) {
                        $inference = 'added at CREATE (in the create member list)';
                    } elseif ($membership->role === 'owner') {
                        $inference = 'group owner (creator)';
                    } elseif ($addedBy) {
                        $inference = 'added LATER by '.$addedBy->display_name;
                    } else {
                        $inference = 'added LATER (added_by missing — pre-migration)';
                    }
                }

                $this->line(sprintf(
                    '  %-20s %-10s %-22s %-22s %-22s %s',
                    $joined?->format('Y-m-d H:i:s') ?? '—',
                    $membership->role,
                    mb_substr($u?->display_name ?? '?', 0, 22),
                    $u?->phone ?? '—',
                    mb_substr($addedByLabel, 0, 22),
                    $inference,
                ));
            }
        }

        $this->newLine();
        $this->line('New memberships store added_by_user_id. Older rows may show "—" until re-added.');

        return self::SUCCESS;
    }
}

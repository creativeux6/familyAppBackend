<?php

namespace App\Modules\Calendar\Services;

use App\Models\CalendarNotificationDelivery;
use App\Models\CalendarReminder;
use App\Models\Connection;
use App\Models\FamilyMember;
use App\Models\RelationshipEdge;
use App\Models\User;
use App\Modules\Devices\Services\DevicePushTokenService;
use App\Modules\Devices\Services\FcmClient;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CalendarService
{
    public const KIND_UPCOMING = 'upcoming';

    public const KIND_TODAY_CONNECTED = 'today_connected';

    public const KIND_TODAY_HONOREE = 'today_honoree';

    public function __construct(
        private readonly DevicePushTokenService $tokenService,
        private readonly FcmClient $fcmClient,
    ) {}

    /** @return array<string, mixed> */
    public function eventsForMonth(User $user, int $year, int $month): array
    {
        $viewerMember = $this->requireFamilyMember($user);
        $events = [];

        $familyMembers = FamilyMember::query()
            ->where('family_uuid', $viewerMember->family_uuid)
            ->get();

        foreach ($familyMembers as $member) {
            if ($member->date_of_birth) {
                $occurrence = $this->projectToMonth($member->date_of_birth, $year, $month);
                if ($occurrence) {
                    $events[] = $this->formatMemberEvent(
                        type: 'birthday',
                        member: $member,
                        date: $occurrence,
                        label: 'Birthday',
                    );
                }
            }

            if (! $member->is_living && $member->date_of_death) {
                $occurrence = $this->projectToMonth($member->date_of_death, $year, $month);
                if ($occurrence) {
                    $events[] = $this->formatMemberEvent(
                        type: 'memorial',
                        member: $member,
                        date: $occurrence,
                        label: 'Memorial',
                    );
                }
            }
        }

        $spouseEdges = RelationshipEdge::query()
            ->whereNotNull('marriage_date')
            ->whereHas('edgeType', fn ($query) => $query->where('code', 'spouse_of'))
            ->where(function ($query) use ($familyMembers) {
                $uuids = $familyMembers->pluck('uuid')->all();
                $query->whereIn('from_member_uuid', $uuids)
                    ->whereIn('to_member_uuid', $uuids);
            })
            ->get();

        $handledAnniversaries = [];

        foreach ($spouseEdges as $edge) {
            $pairKey = $this->pairKey($edge->from_member_uuid, $edge->to_member_uuid);
            if (isset($handledAnniversaries[$pairKey])) {
                continue;
            }
            $handledAnniversaries[$pairKey] = true;

            $occurrence = $this->projectToMonth($edge->marriage_date, $year, $month);
            if (! $occurrence) {
                continue;
            }

            $left = $familyMembers->firstWhere('uuid', $edge->from_member_uuid);
            $right = $familyMembers->firstWhere('uuid', $edge->to_member_uuid);
            if (! $left || ! $right) {
                continue;
            }

            $events[] = [
                'uuid' => "anniversary-{$pairKey}-{$occurrence}",
                'event_type' => 'anniversary',
                'label' => 'Anniversary',
                'title' => trim($left->first_name.' '.$left->last_name).' & '.trim($right->first_name.' '.$right->last_name),
                'date' => $occurrence,
                'member_uuid' => $left->uuid,
                'related_member_uuid' => $right->uuid,
                'source' => 'family_tree',
                'visibility' => 'family_tree',
            ];
        }

        $reminders = CalendarReminder::query()
            ->where('is_enabled', true)
            ->where(function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhere('visibility', CalendarReminder::VISIBILITY_CONNECTED_ONLY);
            })
            ->whereIn('visibility', [
                CalendarReminder::VISIBILITY_PERSONAL,
                CalendarReminder::VISIBILITY_CONNECTED_ONLY,
            ])
            ->get()
            ->filter(fn (CalendarReminder $reminder) => $this->reminderVisibleTo($reminder, $user));

        foreach ($reminders as $reminder) {
            $occurrence = $reminder->recurring_yearly
                ? $this->projectToMonth($reminder->event_date, $year, $month)
                : ($reminder->event_date->year === $year && $reminder->event_date->month === $month
                    ? $reminder->event_date->format('Y-m-d')
                    : null);

            if (! $occurrence) {
                continue;
            }

            $events[] = [
                'uuid' => $reminder->uuid,
                'event_type' => $reminder->event_type,
                'label' => 'Reminder',
                'title' => $reminder->title,
                'date' => $occurrence,
                'member_uuid' => $reminder->family_member_uuid,
                'source' => 'reminder',
                'visibility' => $reminder->visibility,
                'is_owned' => $reminder->user_id === $user->id,
            ];
        }

        usort($events, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return [
            'year' => $year,
            'month' => $month,
            'events' => $events,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function createReminder(User $user, array $data): array
    {
        $member = $this->requireFamilyMember($user);

        $reminder = CalendarReminder::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'family_member_uuid' => $member->uuid,
            'title' => $data['title'],
            'notes' => $data['notes'] ?? null,
            'event_date' => $data['event_date'],
            'event_type' => CalendarReminder::TYPE_PERSONAL_REMINDER,
            'visibility' => $data['visibility'] ?? CalendarReminder::VISIBILITY_PERSONAL,
            'notify_days_before' => $data['notify_days_before'] ?? 3,
            'recurring_yearly' => (bool) ($data['recurring_yearly'] ?? false),
            'is_enabled' => true,
        ]);

        return $this->formatReminder($reminder, $user);
    }

    public function deleteReminder(User $user, string $reminderUuid): void
    {
        $reminder = CalendarReminder::query()->findOrFail($reminderUuid);

        if ($reminder->user_id !== $user->id) {
            abort(403, 'You can only delete your own reminders.');
        }

        $reminder->delete();
    }

    /** @return array<string, mixed> */
    private function formatReminder(CalendarReminder $reminder, User $viewer): array
    {
        return [
            'uuid' => $reminder->uuid,
            'title' => $reminder->title,
            'notes' => $reminder->notes,
            'event_date' => $reminder->event_date->format('Y-m-d'),
            'event_type' => $reminder->event_type,
            'visibility' => $reminder->visibility,
            'notify_days_before' => $reminder->notify_days_before,
            'recurring_yearly' => $reminder->recurring_yearly,
            'is_enabled' => $reminder->is_enabled,
            'is_owned' => $reminder->user_id === $viewer->id,
        ];
    }

    /** @return array<string, mixed> */
    public function todayHighlights(User $user): array
    {
        $viewerMember = $this->requireFamilyMember($user);
        $today = now()->format('Y-m-d');
        $year = (int) now()->year;
        $month = (int) now()->month;

        $monthData = $this->eventsForMonth($user, $year, $month);
        $todayEvents = array_values(array_filter(
            $monthData['events'],
            fn (array $event) => $event['date'] === $today,
        ));

        $personal = [];
        $family = [];

        foreach ($todayEvents as $event) {
            $isPersonal = $this->isPersonalEvent($event, $viewerMember);
            $involvesConnected = $this->eventInvolvesConnectedMember($event, $user);

            if (! $isPersonal && $event['event_type'] !== 'memorial' && ! $involvesConnected) {
                continue;
            }

            $highlight = [
                'uuid' => $event['uuid'],
                'event_type' => $event['event_type'],
                'label' => $event['label'],
                'title' => $event['title'],
                'date' => $event['date'],
                'member_uuid' => $event['member_uuid'] ?? null,
                'related_member_uuid' => $event['related_member_uuid'] ?? null,
                'decoration' => $event['event_type'],
                'message' => $isPersonal
                    ? $this->honoreeHomeMessage($event, $viewerMember)
                    : $this->connectedHomeMessage($event),
                'is_personal' => $isPersonal,
                'is_connected' => $involvesConnected,
            ];

            if ($isPersonal) {
                $personal[] = $highlight;
            } else {
                $family[] = $highlight;
            }
        }

        return [
            'date' => $today,
            'viewer_member_uuid' => $viewerMember->uuid,
            'personal' => $personal,
            'family' => $family,
        ];
    }

    public function sendTodayCelebrations(): int
    {
        if (! $this->fcmClient->isConfigured()) {
            return 0;
        }

        $today = now()->startOfDay();
        $occurrence = $today->format('Y-m-d');
        $sent = 0;

        $registeredMembers = FamilyMember::query()
            ->whereNotNull('user_id')
            ->with('user')
            ->get();

        foreach ($registeredMembers as $member) {
            if (! $member->user) {
                continue;
            }

            $name = $this->displayName($member);
            $pronoun = $this->pronounObject($member);

            if ($member->date_of_birth && $this->matchesUpcoming($member->date_of_birth, $today)) {
                $recipients = $this->connectedUsersFor($member->user);
                $sent += $this->notifyRecipients(
                    recipients: $recipients,
                    sourceMemberUuid: $member->uuid,
                    eventType: 'birthday',
                    occurrenceDate: $occurrence,
                    notificationKind: self::KIND_TODAY_CONNECTED,
                    title: 'Birthday today',
                    body: "Today is {$name}'s birthday! Wish {$pronoun} a wonderful day.",
                    pushType: 'calendar.today',
                );

                $sent += $this->notifyRecipients(
                    recipients: [$member->user],
                    sourceMemberUuid: $member->uuid,
                    eventType: 'birthday',
                    occurrenceDate: $occurrence,
                    notificationKind: self::KIND_TODAY_HONOREE,
                    title: 'Happy birthday!',
                    body: "Happy birthday, {$member->first_name}! Wishing you joy and love from your family today.",
                    pushType: 'calendar.today',
                );
            }

            if (! $member->is_living && $member->date_of_death
                && $this->matchesUpcoming($member->date_of_death, $today)) {
                $sent += $this->notifyFamilyConnectionsAboutMember(
                    member: $member,
                    eventType: 'memorial',
                    occurrenceDate: $occurrence,
                    notificationKind: self::KIND_TODAY_CONNECTED,
                    title: 'Memorial today',
                    body: "Today we remember {$name}. Share a kind thought with family.",
                    pushType: 'calendar.today',
                );
            }
        }

        $deceasedToday = FamilyMember::query()
            ->where('is_living', false)
            ->whereNotNull('date_of_death')
            ->whereNull('user_id')
            ->get();

        foreach ($deceasedToday as $member) {
            if (! $this->matchesUpcoming($member->date_of_death, $today)) {
                continue;
            }

            $name = $this->displayName($member);
            $sent += $this->notifyFamilyConnectionsAboutMember(
                member: $member,
                eventType: 'memorial',
                occurrenceDate: $occurrence,
                notificationKind: self::KIND_TODAY_CONNECTED,
                title: 'Memorial today',
                body: "Today we remember {$name}. Share a kind thought with family.",
                pushType: 'calendar.today',
            );
        }

        $anniversaryEdges = RelationshipEdge::query()
            ->whereNotNull('marriage_date')
            ->whereHas('edgeType', fn ($query) => $query->where('code', 'spouse_of'))
            ->get();

        $handledAnniversaries = [];

        foreach ($anniversaryEdges as $edge) {
            if (! $this->matchesUpcoming($edge->marriage_date, $today)) {
                continue;
            }

            $pairKey = $this->pairKey($edge->from_member_uuid, $edge->to_member_uuid);
            if (isset($handledAnniversaries[$pairKey])) {
                continue;
            }
            $handledAnniversaries[$pairKey] = true;

            $left = FamilyMember::query()->find($edge->from_member_uuid);
            $right = FamilyMember::query()->find($edge->to_member_uuid);
            if (! $left || ! $right) {
                continue;
            }

            $coupleName = $this->displayName($left).' & '.$this->displayName($right);

            foreach ([$left, $right] as $member) {
                if (! $member->user_id) {
                    continue;
                }

                $user = User::query()->find($member->user_id);
                if (! $user) {
                    continue;
                }

                $recipients = $this->connectedUsersFor($user);
                $sent += $this->notifyRecipients(
                    recipients: $recipients,
                    sourceMemberUuid: $member->uuid,
                    eventType: 'anniversary',
                    occurrenceDate: $occurrence,
                    notificationKind: self::KIND_TODAY_CONNECTED,
                    title: 'Anniversary today',
                    body: "Today is {$coupleName}'s wedding anniversary! Send them your warm wishes.",
                    pushType: 'calendar.today',
                );

                $sent += $this->notifyRecipients(
                    recipients: [$user],
                    sourceMemberUuid: $member->uuid,
                    eventType: 'anniversary',
                    occurrenceDate: $occurrence,
                    notificationKind: self::KIND_TODAY_HONOREE,
                    title: 'Happy anniversary!',
                    body: 'Happy anniversary! May you celebrate another beautiful year together.',
                    pushType: 'calendar.today',
                );
            }
        }

        $sent += $this->sendCustomReminderNotifications(
            notificationKind: self::KIND_TODAY_CONNECTED,
            pushType: 'calendar.today',
            daysBefore: 0,
        );

        return $sent;
    }

    public function sendUpcomingReminders(int $daysBefore = 3): int
    {
        if (! $this->fcmClient->isConfigured()) {
            return 0;
        }

        $targetDate = now()->addDays($daysBefore)->startOfDay();
        $sent = 0;

        $registeredMembers = FamilyMember::query()
            ->whereNotNull('user_id')
            ->with('user')
            ->get();

        foreach ($registeredMembers as $member) {
            if (! $member->user) {
                continue;
            }

            $name = trim($member->first_name.' '.$member->last_name) ?: 'A family member';
            $recipients = $this->connectedUsersFor($member->user);

            if ($member->date_of_birth && $this->matchesUpcoming($member->date_of_birth, $targetDate)) {
                $occurrence = $targetDate->copy()->setYear(now()->year)->format('Y-m-d');
                $sent += $this->notifyRecipients(
                    recipients: $recipients,
                    sourceMemberUuid: $member->uuid,
                    eventType: 'birthday',
                    occurrenceDate: $occurrence,
                    notificationKind: self::KIND_UPCOMING,
                    title: 'Upcoming birthday',
                    body: "{$name}'s birthday is in {$daysBefore} days",
                );
            }

            if (! $member->is_living && $member->date_of_death
                && $this->matchesUpcoming($member->date_of_death, $targetDate)) {
                $occurrence = $targetDate->copy()->setYear(now()->year)->format('Y-m-d');
                $sent += $this->notifyFamilyConnectionsAboutMember(
                    member: $member,
                    eventType: 'memorial',
                    occurrenceDate: $occurrence,
                    notificationKind: self::KIND_UPCOMING,
                    title: 'Upcoming memorial',
                    body: "{$name}'s memorial date is in {$daysBefore} days",
                );
            }
        }

        $deceasedUpcoming = FamilyMember::query()
            ->where('is_living', false)
            ->whereNotNull('date_of_death')
            ->whereNull('user_id')
            ->get();

        foreach ($deceasedUpcoming as $member) {
            if (! $this->matchesUpcoming($member->date_of_death, $targetDate)) {
                continue;
            }

            $name = $this->displayName($member);
            $occurrence = $targetDate->copy()->setYear(now()->year)->format('Y-m-d');
            $sent += $this->notifyFamilyConnectionsAboutMember(
                member: $member,
                eventType: 'memorial',
                occurrenceDate: $occurrence,
                notificationKind: self::KIND_UPCOMING,
                title: 'Upcoming memorial',
                body: "{$name}'s memorial date is in {$daysBefore} days",
            );
        }

        $anniversaryEdges = RelationshipEdge::query()
            ->whereNotNull('marriage_date')
            ->whereHas('edgeType', fn ($query) => $query->where('code', 'spouse_of'))
            ->with(['edgeType'])
            ->get();

        foreach ($anniversaryEdges as $edge) {
            if (! $this->matchesUpcoming($edge->marriage_date, $targetDate)) {
                continue;
            }

            $left = FamilyMember::query()->find($edge->from_member_uuid);
            $right = FamilyMember::query()->find($edge->to_member_uuid);
            if (! $left || ! $right) {
                continue;
            }

            $coupleName = trim($left->first_name.' '.$left->last_name).' & '.trim($right->first_name.' '.$right->last_name);
            $occurrence = $targetDate->copy()->setYear(now()->year)->format('Y-m-d');

            foreach ([$left, $right] as $member) {
                if (! $member->user_id) {
                    continue;
                }
                $user = User::query()->find($member->user_id);
                if (! $user) {
                    continue;
                }

                $recipients = $this->connectedUsersFor($user);
                $sent += $this->notifyRecipients(
                    recipients: $recipients,
                    sourceMemberUuid: $member->uuid,
                    eventType: 'anniversary',
                    occurrenceDate: $occurrence,
                    notificationKind: self::KIND_UPCOMING,
                    title: 'Upcoming anniversary',
                    body: "{$coupleName}'s anniversary is in {$daysBefore} days",
                );
            }
        }

        $sent += $this->sendCustomReminderNotifications(
            notificationKind: self::KIND_UPCOMING,
            pushType: 'calendar.upcoming',
            daysBefore: $daysBefore,
        );

        return $sent;
    }

    private function notifyFamilyConnectionsAboutMember(
        FamilyMember $member,
        string $eventType,
        string $occurrenceDate,
        string $notificationKind,
        string $title,
        string $body,
        string $pushType = 'calendar.upcoming',
    ): int {
        $familyUsers = FamilyMember::query()
            ->where('family_uuid', $member->family_uuid)
            ->whereNotNull('user_id')
            ->with('user')
            ->get();

        $recipients = [];
        foreach ($familyUsers as $familyMember) {
            if (! $familyMember->user) {
                continue;
            }

            foreach ($this->connectedUsersFor($familyMember->user) as $user) {
                $recipients[$user->id] = $user;
            }
        }

        return $this->notifyRecipients(
            recipients: array_values($recipients),
            sourceMemberUuid: $member->uuid,
            eventType: $eventType,
            occurrenceDate: $occurrenceDate,
            notificationKind: $notificationKind,
            title: $title,
            body: $body,
            pushType: $pushType,
        );
    }

    /** @param  iterable<User>  $recipients */
    private function notifyRecipients(
        iterable $recipients,
        ?string $sourceMemberUuid,
        string $eventType,
        string $occurrenceDate,
        string $notificationKind,
        string $title,
        string $body,
        string $pushType = 'calendar.upcoming',
    ): int {
        $sent = 0;

        foreach ($recipients as $recipient) {
            if ($sourceMemberUuid && CalendarNotificationDelivery::query()
                ->where('recipient_user_id', $recipient->id)
                ->where('source_member_uuid', $sourceMemberUuid)
                ->where('event_type', $eventType)
                ->where('notification_kind', $notificationKind)
                ->whereDate('occurrence_date', $occurrenceDate)
                ->exists()) {
                continue;
            }

            $tokens = $this->tokenService->tokensForUser($recipient);
            if ($tokens === []) {
                continue;
            }

            foreach ($tokens as $token) {
                $this->fcmClient->send(
                    $token,
                    $title,
                    $body,
                    [
                        'type' => $pushType,
                        'event_type' => $eventType,
                        'notification_kind' => $notificationKind,
                        'source_member_uuid' => $sourceMemberUuid,
                        'occurrence_date' => $occurrenceDate,
                    ],
                    0,
                );
            }

            CalendarNotificationDelivery::create([
                'uuid' => (string) Str::uuid(),
                'recipient_user_id' => $recipient->id,
                'source_member_uuid' => $sourceMemberUuid,
                'event_type' => $eventType,
                'notification_kind' => $notificationKind,
                'occurrence_date' => $occurrenceDate,
                'notified_at' => now(),
            ]);

            $sent++;
        }

        return $sent;
    }

    private function sendCustomReminderNotifications(
        string $notificationKind,
        string $pushType,
        int $daysBefore,
    ): int {
        $sent = 0;
        $isToday = $daysBefore === 0;

        $reminders = CalendarReminder::query()
            ->where('is_enabled', true)
            ->with('user')
            ->get();

        foreach ($reminders as $reminder) {
            if (! $reminder->user) {
                continue;
            }

            $targetDate = $isToday
                ? now()->startOfDay()
                : now()->addDays($reminder->notify_days_before ?: $daysBefore)->startOfDay();

            if (! $this->reminderMatchesDate($reminder, $targetDate)) {
                continue;
            }

            $occurrence = $reminder->recurring_yearly
                ? $targetDate->copy()->setYear(now()->year)->format('Y-m-d')
                : $reminder->event_date->format('Y-m-d');

            $recipients = [$reminder->user];
            if ($reminder->visibility === CalendarReminder::VISIBILITY_CONNECTED_ONLY) {
                $recipients = array_merge(
                    $recipients,
                    $this->connectedUsersFor($reminder->user),
                );
            }

            $uniqueRecipients = [];
            foreach ($recipients as $recipient) {
                $uniqueRecipients[$recipient->id] = $recipient;
            }

            $title = $isToday ? 'Reminder today' : 'Upcoming reminder';
            $body = $isToday
                ? "Today: {$reminder->title}"
                : "{$reminder->title} is in ".($reminder->notify_days_before ?: $daysBefore).' days';

            $sent += $this->notifyReminderRecipients(
                recipients: array_values($uniqueRecipients),
                reminder: $reminder,
                occurrenceDate: $occurrence,
                notificationKind: $notificationKind,
                title: $title,
                body: $body,
                pushType: $pushType,
            );
        }

        return $sent;
    }

    private function reminderMatchesDate(CalendarReminder $reminder, Carbon $targetDate): bool
    {
        if ($reminder->recurring_yearly) {
            return $this->matchesUpcoming($reminder->event_date, $targetDate);
        }

        return $reminder->event_date->isSameDay($targetDate);
    }

    /** @param  iterable<User>  $recipients */
    private function notifyReminderRecipients(
        iterable $recipients,
        CalendarReminder $reminder,
        string $occurrenceDate,
        string $notificationKind,
        string $title,
        string $body,
        string $pushType = 'calendar.upcoming',
    ): int {
        $sent = 0;

        foreach ($recipients as $recipient) {
            if (CalendarNotificationDelivery::query()
                ->where('recipient_user_id', $recipient->id)
                ->where('calendar_reminder_uuid', $reminder->uuid)
                ->where('notification_kind', $notificationKind)
                ->whereDate('occurrence_date', $occurrenceDate)
                ->exists()) {
                continue;
            }

            $tokens = $this->tokenService->tokensForUser($recipient);
            if ($tokens === []) {
                continue;
            }

            foreach ($tokens as $token) {
                $this->fcmClient->send(
                    $token,
                    $title,
                    $body,
                    [
                        'type' => $pushType,
                        'event_type' => $reminder->event_type,
                        'notification_kind' => $notificationKind,
                        'reminder_uuid' => $reminder->uuid,
                        'occurrence_date' => $occurrenceDate,
                    ],
                    0,
                );
            }

            CalendarNotificationDelivery::create([
                'uuid' => (string) Str::uuid(),
                'recipient_user_id' => $recipient->id,
                'source_member_uuid' => $reminder->family_member_uuid,
                'calendar_reminder_uuid' => $reminder->uuid,
                'event_type' => $reminder->event_type,
                'notification_kind' => $notificationKind,
                'occurrence_date' => $occurrenceDate,
                'notified_at' => now(),
            ]);

            $sent++;
        }

        return $sent;
    }

    /** @return list<User> */
    private function connectedUsersFor(User $subject): array
    {
        return Connection::query()
            ->where('status', 'connected')
            ->where(function ($query) use ($subject) {
                $query->where('requester_user_id', $subject->id)
                    ->orWhere('recipient_user_id', $subject->id);
            })
            ->with(['requester', 'recipient'])
            ->get()
            ->map(function (Connection $connection) use ($subject) {
                return $connection->requester_user_id === $subject->id
                    ? $connection->recipient
                    : $connection->requester;
            })
            ->filter()
            ->unique('id')
            ->values()
            ->all();
    }

    private function matchesUpcoming(Carbon|\DateTimeInterface|string $sourceDate, Carbon $targetDate): bool
    {
        $source = Carbon::parse($sourceDate);

        return $source->month === $targetDate->month && $source->day === $targetDate->day;
    }

    private function projectToMonth(Carbon|\DateTimeInterface|string $sourceDate, int $year, int $month): ?string
    {
        $source = Carbon::parse($sourceDate);
        if ((int) $source->month !== $month) {
            return null;
        }

        $day = min($source->day, Carbon::create($year, $month, 1)->daysInMonth);

        return Carbon::create($year, $month, $day)->format('Y-m-d');
    }

    private function reminderVisibleTo(CalendarReminder $reminder, User $viewer): bool
    {
        if ($reminder->user_id === $viewer->id) {
            return true;
        }

        if ($reminder->visibility !== CalendarReminder::VISIBILITY_CONNECTED_ONLY) {
            return false;
        }

        $owner = User::query()->find($reminder->user_id);
        if (! $owner) {
            return false;
        }

        return Connection::query()
            ->where('status', 'connected')
            ->where(function ($query) use ($viewer, $owner) {
                $query->where(function ($inner) use ($viewer, $owner) {
                    $inner->where('requester_user_id', $viewer->id)
                        ->where('recipient_user_id', $owner->id);
                })->orWhere(function ($inner) use ($viewer, $owner) {
                    $inner->where('requester_user_id', $owner->id)
                        ->where('recipient_user_id', $viewer->id);
                });
            })
            ->exists();
    }

    /** @return array<string, mixed> */
    private function formatMemberEvent(
        string $type,
        FamilyMember $member,
        string $date,
        string $label,
    ): array {
        $name = trim($member->first_name.' '.$member->last_name) ?: 'Family member';

        return [
            'uuid' => "{$type}-{$member->uuid}-{$date}",
            'event_type' => $type,
            'label' => $label,
            'title' => $name,
            'date' => $date,
            'member_uuid' => $member->uuid,
            'source' => 'family_tree',
            'visibility' => 'family_tree',
        ];
    }

    private function pairKey(string $left, string $right): string
    {
        return $left <= $right ? "{$left}|{$right}" : "{$right}|{$left}";
    }

    private function requireFamilyMember(User $user): FamilyMember
    {
        $member = FamilyMember::query()->where('user_id', $user->id)->first();
        if (! $member) {
            throw new \RuntimeException('Family member record not found.');
        }

        return $member;
    }

    /** @param  array<string, mixed>  $event */
    private function isPersonalEvent(array $event, FamilyMember $viewerMember): bool
    {
        if (($event['member_uuid'] ?? null) === $viewerMember->uuid) {
            return in_array($event['event_type'], ['birthday', 'anniversary'], true);
        }

        if ($event['event_type'] === 'anniversary'
            && ($event['related_member_uuid'] ?? null) === $viewerMember->uuid) {
            return true;
        }

        return false;
    }

    /** @param  array<string, mixed>  $event */
    private function eventInvolvesConnectedMember(array $event, User $viewer): bool
    {
        foreach ([$event['member_uuid'] ?? null, $event['related_member_uuid'] ?? null] as $memberUuid) {
            if (! $memberUuid) {
                continue;
            }

            $member = FamilyMember::query()->find($memberUuid);
            if (! $member?->user_id) {
                continue;
            }

            if ($member->user_id === $viewer->id) {
                continue;
            }

            if ($this->usersAreConnected($viewer->id, $member->user_id)) {
                return true;
            }
        }

        return false;
    }

    private function usersAreConnected(int $leftUserId, int $rightUserId): bool
    {
        return Connection::query()
            ->where('status', 'connected')
            ->where(function ($query) use ($leftUserId, $rightUserId) {
                $query->where(function ($inner) use ($leftUserId, $rightUserId) {
                    $inner->where('requester_user_id', $leftUserId)
                        ->where('recipient_user_id', $rightUserId);
                })->orWhere(function ($inner) use ($leftUserId, $rightUserId) {
                    $inner->where('requester_user_id', $rightUserId)
                        ->where('recipient_user_id', $leftUserId);
                });
            })
            ->exists();
    }

    /** @param  array<string, mixed>  $event */
    private function honoreeHomeMessage(array $event, FamilyMember $viewerMember): string
    {
        return match ($event['event_type']) {
            'birthday' => 'Happy birthday, '.$viewerMember->first_name.'! Wishing you a joyful day.',
            'anniversary' => 'Happy anniversary! Celebrate your special day together.',
            'memorial' => 'Remembering loved ones with your family today.',
            default => 'A special day for you and your family.',
        };
    }

    /** @param  array<string, mixed>  $event */
    private function connectedHomeMessage(array $event): string
    {
        $title = $event['title'] ?? 'A family member';

        return match ($event['event_type']) {
            'birthday' => "Today is {$title}'s birthday — wish them a wonderful day!",
            'anniversary' => "Today is {$title}'s wedding anniversary — send warm wishes!",
            'memorial' => "Today we remember {$title}. Share a kind thought with family.",
            default => "Today: {$title}",
        };
    }

    private function displayName(FamilyMember $member): string
    {
        $name = trim($member->first_name.' '.$member->last_name);

        return $name !== '' ? $name : 'A family member';
    }

    private function pronounObject(FamilyMember $member): string
    {
        return match ($member->gender) {
            'female' => 'her',
            'male' => 'him',
            default => 'them',
        };
    }
}

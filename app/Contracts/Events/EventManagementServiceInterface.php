<?php

namespace App\Contracts\Events;

use App\Models\MediaEvent;
use App\Models\User;

/**
 * v2 event management surface.
 *
 * v1 media events are photo/video folders only. Implementations of this
 * interface will power expenses, bookings, tasks, and collaborators when
 * EVENT_MANAGEMENT_ENABLED=true (v2).
 */
interface EventManagementServiceInterface
{
    /** @param  array<string, mixed>  $data */
    public function enableManagement(User $user, MediaEvent $event, array $data = []): MediaEvent;

    /** @return array<string, mixed> */
    public function summarize(User $user, MediaEvent $event): array;

    /** @param  array<string, mixed>  $data */
    public function addExpense(User $user, MediaEvent $event, array $data): array;

    /** @param  array<string, mixed>  $data */
    public function addBooking(User $user, MediaEvent $event, array $data): array;

    /** @param  array<string, mixed>  $data */
    public function addTask(User $user, MediaEvent $event, array $data): array;

    public function inviteCollaborator(User $user, MediaEvent $event, string $userUuid, string $role = 'viewer'): array;
}

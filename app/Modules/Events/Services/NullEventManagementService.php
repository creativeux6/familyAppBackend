<?php

namespace App\Modules\Events\Services;

use App\Contracts\Events\EventManagementServiceInterface;
use App\Models\MediaEvent;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * v1 stub: schema and models exist, but event management APIs stay closed.
 * Replace wiring with a full implementation when EVENT_MANAGEMENT_ENABLED=true.
 */
class NullEventManagementService implements EventManagementServiceInterface
{
    public function enableManagement(User $user, MediaEvent $event, array $data = []): MediaEvent
    {
        $this->rejectUntilV2();
    }

    public function summarize(User $user, MediaEvent $event): array
    {
        $this->rejectUntilV2();
    }

    public function addExpense(User $user, MediaEvent $event, array $data): array
    {
        $this->rejectUntilV2();
    }

    public function addBooking(User $user, MediaEvent $event, array $data): array
    {
        $this->rejectUntilV2();
    }

    public function addTask(User $user, MediaEvent $event, array $data): array
    {
        $this->rejectUntilV2();
    }

    public function inviteCollaborator(User $user, MediaEvent $event, string $userUuid, string $role = 'viewer'): array
    {
        $this->rejectUntilV2();
    }

    private function rejectUntilV2(): never
    {
        throw ValidationException::withMessages([
            'event_management' => [
                'Event management (expenses, bookings, tasks) is reserved for v2. '
                .'Set EVENT_MANAGEMENT_ENABLED=true and use a full implementation when ready.',
            ],
        ]);
    }
}

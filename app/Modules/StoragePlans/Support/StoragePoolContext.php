<?php

namespace App\Modules\StoragePlans\Support;

use App\Models\User;
use App\Models\UserPlanAssignment;
use App\Models\UserStorageUsage;

final class StoragePoolContext
{
    public function __construct(
        public readonly User $actor,
        public readonly UserPlanAssignment $assignment,
        public readonly UserStorageUsage $usage,
        public readonly bool $isOwner,
    ) {}

    public function ownerUserId(): int
    {
        return (int) $this->assignment->user_id;
    }
}

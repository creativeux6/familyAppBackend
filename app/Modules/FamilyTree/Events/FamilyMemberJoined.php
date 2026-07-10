<?php

namespace App\Modules\FamilyTree\Events;

use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\User;

class FamilyMemberJoined
{
    public function __construct(
        public readonly User $joinedUser,
        public readonly Family $family,
        public readonly FamilyMember $member,
    ) {}
}

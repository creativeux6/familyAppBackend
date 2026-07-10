<?php

use App\Models\User;
use App\Modules\Groups\Services\GroupService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('group.{groupUuid}', function (User $user, string $groupUuid) {
    return app(GroupService::class)->isGroupMember($user, $groupUuid);
});

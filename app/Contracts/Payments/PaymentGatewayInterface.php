<?php

namespace App\Contracts\Payments;

use App\Models\StoragePlan;
use App\Models\User;

interface PaymentGatewayInterface
{
    public function assignPlan(User $user, StoragePlan $plan, ?User $assignedBy = null): void;

    public function isEnabled(): bool;
}

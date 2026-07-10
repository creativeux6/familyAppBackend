<?php

namespace App\Modules\StoragePlans\Services;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Models\StoragePlan;
use App\Models\User;
use App\Models\UserPlanAssignment;
use Illuminate\Support\Facades\DB;

class ManualPlanGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly PlanAssignmentService $assignmentService,
    ) {}

    public function assignPlan(User $user, StoragePlan $plan, ?User $assignedBy = null): void
    {
        $this->assignmentService->assign($user, $plan, $assignedBy, 'admin_manual');
    }

    public function isEnabled(): bool
    {
        return ! config('features.payments_enabled', false);
    }
}

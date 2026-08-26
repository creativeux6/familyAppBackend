<?php

namespace App\Modules\StoragePlans\Services;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Models\PaymentTransaction;
use App\Models\StoragePlan;
use App\Models\User;
use Illuminate\Support\Str;

class ManualPlanGateway implements PaymentGatewayInterface
{
    public function assignPlan(User $user, StoragePlan $plan, ?User $assignedBy = null): void
    {
        app(PlanAssignmentService::class)->changePlan($user, $plan, $assignedBy, 'admin_manual');
    }

    public function isEnabled(): bool
    {
        return ! config('features.payments_enabled', false);
    }

    public function charge(User $user, StoragePlan $plan, string $reason = 'renewal'): bool
    {
        $succeed = (bool) config('payments.stub_succeed', true);

        PaymentTransaction::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'storage_plan_uuid' => $plan->uuid,
            'amount_cents' => (int) $plan->display_price_cents,
            'currency' => $plan->currency ?: 'USD',
            'provider' => 'manual_stub',
            'provider_reference' => $reason,
            'status' => $succeed ? 'completed' : 'failed',
            'provider_payload' => ['reason' => $reason, 'stub' => true],
        ]);

        return $succeed;
    }
}

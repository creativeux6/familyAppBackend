<?php

namespace App\Modules\StoragePlans\Services;

use App\Contracts\Payments\PaymentGatewayInterface;
use App\Models\User;
use App\Models\UserPlanAssignment;
use App\Modules\Devices\Services\PushNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PlanBillingService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly StoragePoolService $poolService,
    ) {}

    public function charge(User $user, \App\Models\StoragePlan $plan, string $reason): bool
    {
        if (! $plan->isPaid()) {
            return true;
        }

        return $this->gateway->charge($user, $plan, $reason);
    }

    public function markPastDue(UserPlanAssignment $assignment): void
    {
        $assignment->update([
            'billing_status' => UserPlanAssignment::STATUS_PAST_DUE,
            'last_payment_failed_at' => $assignment->last_payment_failed_at ?? now(),
            'payment_retry_count' => (int) $assignment->payment_retry_count,
            'next_retry_at' => now()->addHours(max(1, (int) config('payments.retry_interval_hours', 24))),
        ]);

        $this->notifyOwner($assignment, 'Payment failed. Retry payment to keep your plan.');
    }

    public function markActive(UserPlanAssignment $assignment): void
    {
        $assignment->update([
            'billing_status' => UserPlanAssignment::STATUS_ACTIVE,
            'last_payment_failed_at' => null,
            'payment_retry_count' => 0,
            'next_retry_at' => null,
        ]);
    }

    public function markMediaLocked(UserPlanAssignment $assignment): void
    {
        $assignment->update([
            'billing_status' => UserPlanAssignment::STATUS_MEDIA_LOCKED,
            'next_retry_at' => null,
        ]);

        $this->notifyOwner(
            $assignment,
            (string) config('media.payment_lock_message', 'Media is locked because payment failed. Retry payment to continue.'),
        );
    }

    public function retryNow(User $owner): UserPlanAssignment
    {
        $assignment = $this->poolService->ownedActiveAssignment($owner)
            ?? $this->poolService->context($owner)->assignment;

        if ((int) $assignment->user_id !== (int) $owner->id) {
            throw ValidationException::withMessages([
                'payment' => ['Only the plan owner can retry payment.'],
            ]);
        }

        if ($assignment->billing_status === UserPlanAssignment::STATUS_ACTIVE) {
            return $assignment;
        }

        $assignment->loadMissing('plan');
        $plan = $assignment->plan;
        if (! $plan) {
            throw ValidationException::withMessages([
                'payment' => ['Assignment has no storage plan.'],
            ]);
        }

        if ($this->charge($owner, $plan, 'manual_retry')) {
            $this->markActive($assignment);

            return $assignment->fresh('plan');
        }

        throw ValidationException::withMessages([
            'payment' => ['Payment failed. Please try again.'],
        ]);
    }

    public function retryPastDueDue(): int
    {
        $due = UserPlanAssignment::query()
            ->where('is_active', true)
            ->whereIn('billing_status', [
                UserPlanAssignment::STATUS_PAST_DUE,
                UserPlanAssignment::STATUS_MEDIA_LOCKED,
            ])
            ->where(function ($q) {
                $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->with(['plan', 'user'])
            ->get();

        $count = 0;
        foreach ($due as $assignment) {
            if ($assignment->billing_status === UserPlanAssignment::STATUS_MEDIA_LOCKED) {
                continue;
            }

            $plan = $assignment->plan;
            $user = $assignment->user;
            if (! $plan || ! $user || ! $plan->isPaid()) {
                continue;
            }

            if ($this->charge($user, $plan, 'auto_retry')) {
                $this->markActive($assignment);
                $count++;

                continue;
            }

            $retries = (int) $assignment->payment_retry_count + 1;
            $assignment->update([
                'payment_retry_count' => $retries,
                'next_retry_at' => now()->addHours(max(1, (int) config('payments.retry_interval_hours', 24))),
            ]);

            if ($this->graceExpired($assignment->fresh())) {
                $this->markMediaLocked($assignment->fresh());
            } else {
                $this->notifyOwner($assignment, 'Payment failed. We will retry, or tap Retry payment.');
            }
            $count++;
        }

        $this->lockExpiredGrace();

        return $count;
    }

    public function lockExpiredGrace(): int
    {
        $locked = 0;
        UserPlanAssignment::query()
            ->where('is_active', true)
            ->where('billing_status', UserPlanAssignment::STATUS_PAST_DUE)
            ->with('plan')
            ->get()
            ->each(function (UserPlanAssignment $assignment) use (&$locked) {
                if ($this->graceExpired($assignment)) {
                    $this->markMediaLocked($assignment);
                    $locked++;
                }
            });

        return $locked;
    }

    public function graceExpired(UserPlanAssignment $assignment): bool
    {
        $failedAt = $assignment->last_payment_failed_at;
        if (! $failedAt instanceof Carbon) {
            return false;
        }

        $graceDays = max(1, (int) config('payments.grace_days', 3));
        $maxRetries = max(1, (int) config('payments.max_auto_retries', 3));

        return $failedAt->copy()->addDays($graceDays)->lte(now())
            || (int) $assignment->payment_retry_count >= $maxRetries;
    }

    private function notifyOwner(UserPlanAssignment $assignment, string $message): void
    {
        $owner = $assignment->user ?? User::query()->find($assignment->user_id);
        if (! $owner) {
            return;
        }

        try {
            app(PushNotificationService::class)->notifyAccessUsageWarning($owner, $message);
        } catch (\Throwable) {
            // Push is best-effort.
        }
    }
}

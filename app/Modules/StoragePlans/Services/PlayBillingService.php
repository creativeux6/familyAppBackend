<?php

namespace App\Modules\StoragePlans\Services;

use App\Models\PaymentTransaction;
use App\Models\StoragePlan;
use App\Models\User;
use App\Models\UserPlanAssignment;
use App\Modules\StoragePlans\Contracts\GooglePlayClientInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlayBillingService
{
    public const NOTIFICATION_RECOVERED = 1;

    public const NOTIFICATION_RENEWED = 2;

    public const NOTIFICATION_CANCELED = 3;

    public const NOTIFICATION_PURCHASED = 4;

    public const NOTIFICATION_ON_HOLD = 5;

    public const NOTIFICATION_IN_GRACE_PERIOD = 6;

    public const NOTIFICATION_RESTARTED = 7;

    public const NOTIFICATION_REVOKED = 12;

    public const NOTIFICATION_EXPIRED = 13;

    public function __construct(
        private readonly GooglePlayClientInterface $play,
        private readonly PlanAssignmentService $assignments,
        private readonly StoragePlanService $plans,
        private readonly PlanBillingService $billing,
    ) {}

    /** @return array<string, mixed> */
    public function verifyAndApply(User $user, string $purchaseToken, string $productId, ?string $planUuid = null): array
    {
        $plan = $this->resolvePlan($productId, $planUuid);
        $purchase = $this->assertValidPurchase($user, $plan, $purchaseToken, $productId);

        $assignment = $this->assignments->applyImmediatePlan(
            $user,
            $plan,
            'google_play',
            [
                'play_purchase_token' => $purchaseToken,
                'play_product_id' => $productId,
                'play_auto_renewing' => $purchase['auto_renewing'],
                'ends_at' => $this->expiryFromMillis($purchase['expiry_time_millis']),
            ],
        );

        $this->recordTransaction($user, $plan, $purchaseToken, $purchase['raw'], 'completed');

        if ($purchase['acknowledgement_state'] !== 1) {
            try {
                $this->play->acknowledgeSubscription($productId, $purchaseToken);
            } catch (\Throwable $e) {
                Log::info('Play acknowledge failed', ['message' => $e->getMessage()]);
            }
        }

        return $this->assignments->formatAssignment($assignment);
    }

    /** @param  array<string, mixed>  $payload */
    public function handleRtdn(array $payload): void
    {
        $decoded = $this->decodeRtdn($payload);
        $notification = $decoded['subscriptionNotification'] ?? null;
        if (! is_array($notification)) {
            return;
        }

        $token = (string) ($notification['purchaseToken'] ?? '');
        $productId = (string) ($notification['subscriptionId'] ?? '');
        $type = (int) ($notification['notificationType'] ?? 0);
        if ($token === '' || $productId === '') {
            return;
        }

        $assignment = UserPlanAssignment::query()
            ->where('play_purchase_token', $token)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        $purchase = null;
        try {
            $purchase = $this->play->getSubscription($productId, $token);
        } catch (\Throwable $e) {
            Log::info('Play RTDN lookup failed', ['message' => $e->getMessage(), 'type' => $type]);
        }

        $user = $assignment?->user;
        if (! $user && is_array($purchase) && filled($purchase['obfuscated_external_account_id'])) {
            $user = User::query()
                ->where('uuid', $purchase['obfuscated_external_account_id'])
                ->first();
        }

        if (! $user) {
            Log::info('Play RTDN ignored: no matching user', ['product_id' => $productId, 'type' => $type]);

            return;
        }

        match ($type) {
            self::NOTIFICATION_PURCHASED,
            self::NOTIFICATION_RENEWED,
            self::NOTIFICATION_RECOVERED,
            self::NOTIFICATION_RESTARTED => $this->applyFromPurchase($user, $productId, $token, $purchase),
            self::NOTIFICATION_CANCELED => $this->markCanceled($user, $token, $purchase),
            self::NOTIFICATION_ON_HOLD,
            self::NOTIFICATION_IN_GRACE_PERIOD => $this->markPastDueForToken($user, $token),
            self::NOTIFICATION_REVOKED,
            self::NOTIFICATION_EXPIRED => $this->dropToFree($user),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>|null  $purchase
     */
    private function applyFromPurchase(User $user, string $productId, string $token, ?array $purchase): void
    {
        try {
            $this->verifyAndApply($user, $token, $productId);
        } catch (\Throwable $e) {
            if (is_array($purchase)) {
                $plan = StoragePlan::query()->where('play_product_id', $productId)->first();
                if ($plan) {
                    $this->assignments->applyImmediatePlan($user, $plan, 'google_play', [
                        'play_purchase_token' => $token,
                        'play_product_id' => $productId,
                        'play_auto_renewing' => (bool) $purchase['auto_renewing'],
                        'ends_at' => $this->expiryFromMillis((int) $purchase['expiry_time_millis']),
                    ]);
                    $this->billing->markActive(
                        $this->assignments->activeAssignment($user) ?? throw ValidationException::withMessages([
                            'assignment' => ['Missing assignment after Play apply.'],
                        ]),
                    );
                }
            }
            Log::info('Play RTDN apply', ['message' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>|null  $purchase */
    private function markCanceled(User $user, string $token, ?array $purchase): void
    {
        $assignment = $this->assignments->activeAssignment($user);
        if (! $assignment) {
            return;
        }

        $updates = [
            'play_auto_renewing' => false,
            'play_purchase_token' => $assignment->play_purchase_token ?: $token,
        ];
        if (is_array($purchase) && (int) $purchase['expiry_time_millis'] > 0) {
            $updates['ends_at'] = $this->expiryFromMillis((int) $purchase['expiry_time_millis']);
        }
        $assignment->update($updates);
    }

    private function markPastDueForToken(User $user, string $token): void
    {
        $assignment = $this->assignments->activeAssignment($user);
        if (! $assignment) {
            return;
        }
        if ($assignment->play_purchase_token && $assignment->play_purchase_token !== $token) {
            $assignment->update(['play_purchase_token' => $token]);
        }
        $this->billing->markPastDue($assignment);
    }

    private function dropToFree(User $user): void
    {
        $free = $this->assignments->ensureFreePlanRow();
        $this->assignments->applyImmediatePlan($user, $free, 'system_default', [
            'play_purchase_token' => null,
            'play_product_id' => null,
            'play_auto_renewing' => false,
        ]);
    }

    /**
     * @return array{
     *     product_id: string,
     *     expiry_time_millis: int,
     *     payment_state: ?int,
     *     acknowledgement_state: int,
     *     auto_renewing: bool,
     *     obfuscated_external_account_id: ?string,
     *     cancel_reason: ?int,
     *     raw: array<string, mixed>
     * }
     */
    private function assertValidPurchase(User $user, StoragePlan $plan, string $purchaseToken, string $productId): array
    {
        if (! $this->play->isConfigured()) {
            throw ValidationException::withMessages([
                'payment' => ['Google Play Billing is not configured on the server.'],
            ]);
        }

        $purchase = $this->play->getSubscription($productId, $purchaseToken);

        $expectedProduct = (string) ($plan->play_product_id ?: $productId);
        if ($expectedProduct !== '' && $expectedProduct !== $productId) {
            throw ValidationException::withMessages([
                'product_id' => ['This purchase does not match the selected plan.'],
            ]);
        }

        $accountId = $purchase['obfuscated_external_account_id'];
        if (is_string($accountId) && $accountId !== '' && $accountId !== $user->uuid) {
            throw ValidationException::withMessages([
                'purchase_token' => ['This purchase belongs to a different Tijori account.'],
            ]);
        }

        $paymentState = $purchase['payment_state'];
        if ($paymentState === 0 || $paymentState === 3) {
            throw ValidationException::withMessages([
                'purchase_token' => ['This purchase is still pending.'],
            ]);
        }

        $expiry = $this->expiryFromMillis($purchase['expiry_time_millis']);
        if ($expiry !== null && $expiry->lte(now())) {
            throw ValidationException::withMessages([
                'purchase_token' => ['This subscription has expired.'],
            ]);
        }

        return $purchase;
    }

    private function resolvePlan(string $productId, ?string $planUuid): StoragePlan
    {
        if (is_string($planUuid) && $planUuid !== '') {
            $plan = $this->plans->requirePlan($planUuid);
            $expected = (string) $plan->play_product_id;
            if ($expected !== '' && $expected !== $productId) {
                throw ValidationException::withMessages([
                    'product_id' => ['This purchase does not match the selected plan.'],
                ]);
            }

            return $plan;
        }

        $plan = StoragePlan::query()
            ->where('play_product_id', $productId)
            ->where('is_active', true)
            ->first();

        if (! $plan) {
            throw ValidationException::withMessages([
                'product_id' => ['No Tijori plan is mapped to this Google Play product.'],
            ]);
        }

        return $plan;
    }

    private function expiryFromMillis(int $millis): ?\Illuminate\Support\Carbon
    {
        if ($millis <= 0) {
            return null;
        }

        return \Illuminate\Support\Carbon::createFromTimestampMs($millis);
    }

    /** @param  array<string, mixed>  $raw */
    private function recordTransaction(User $user, StoragePlan $plan, string $token, array $raw, string $status): void
    {
        PaymentTransaction::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'storage_plan_uuid' => $plan->uuid,
            'amount_cents' => (int) $plan->display_price_cents,
            'currency' => $plan->currency ?: 'USD',
            'provider' => 'google_play',
            'provider_reference' => $token,
            'status' => $status,
            'provider_payload' => $raw,
        ]);
    }

    /** @param  array<string, mixed>  $payload @return array<string, mixed> */
    private function decodeRtdn(array $payload): array
    {
        $data = $payload['message']['data'] ?? null;
        if (is_string($data) && $data !== '') {
            $decoded = json_decode(base64_decode($data), true);

            return is_array($decoded) ? $decoded : [];
        }

        if (isset($payload['subscriptionNotification'])) {
            return $payload;
        }

        return [];
    }
}

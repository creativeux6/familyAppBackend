<?php

namespace App\Modules\StoragePlans\Services;

use App\Modules\StoragePlans\Contracts\GooglePlayClientInterface;

/**
 * Accepts purchase tokens without calling Google. Enable only via
 * GOOGLE_PLAY_STUB_VERIFY when the Android Publisher API is not configured
 * (local / closed-test). Never leave on in production with real billing.
 */
class StubGooglePlayClient implements GooglePlayClientInterface
{
    public function isConfigured(): bool
    {
        return true;
    }

    public function getSubscription(string $productId, string $purchaseToken): array
    {
        return [
            'product_id' => $productId,
            'expiry_time_millis' => (int) (now()->addMonth()->timestamp * 1000),
            'payment_state' => 1,
            'acknowledgement_state' => 1,
            'auto_renewing' => true,
            // Null = skip account-id binding check (Play may omit it in tests).
            'obfuscated_external_account_id' => null,
            'cancel_reason' => null,
            'raw' => [
                'stub' => true,
                'purchaseToken' => $purchaseToken,
                'productId' => $productId,
            ],
        ];
    }

    public function acknowledgeSubscription(string $productId, string $purchaseToken): void
    {
        // No-op — Google is not contacted in stub mode.
    }
}

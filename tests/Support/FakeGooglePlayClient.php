<?php

namespace Tests\Support;

use App\Modules\StoragePlans\Contracts\GooglePlayClientInterface;

class FakeGooglePlayClient implements GooglePlayClientInterface
{
    /** @var array<string, mixed> */
    public array $purchase = [];

    public bool $configured = true;

    public bool $acknowledged = false;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function getSubscription(string $productId, string $purchaseToken): array
    {
        $defaults = [
            'product_id' => $productId,
            'expiry_time_millis' => (int) (now()->addMonth()->timestamp * 1000),
            'payment_state' => 1,
            'acknowledgement_state' => 0,
            'auto_renewing' => true,
            'obfuscated_external_account_id' => $this->purchase['obfuscated_external_account_id'] ?? null,
            'cancel_reason' => null,
            'raw' => ['purchaseToken' => $purchaseToken, 'productId' => $productId],
        ];

        return array_merge($defaults, $this->purchase, [
            'product_id' => $productId,
            'raw' => array_merge($defaults['raw'], $this->purchase['raw'] ?? []),
        ]);
    }

    public function acknowledgeSubscription(string $productId, string $purchaseToken): void
    {
        $this->acknowledged = true;
    }
}

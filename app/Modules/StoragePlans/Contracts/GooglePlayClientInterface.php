<?php

namespace App\Modules\StoragePlans\Contracts;

interface GooglePlayClientInterface
{
    public function isConfigured(): bool;

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
    public function getSubscription(string $productId, string $purchaseToken): array;

    public function acknowledgeSubscription(string $productId, string $purchaseToken): void;
}

<?php

namespace App\Modules\StoragePlans\Support;

use App\Models\UserStorageUsage;

class B2CostEstimator
{
    public function storageUsdPerGbMonth(): float
    {
        return max(0, (float) config('media.b2_storage_usd_per_gb_month', 0.006));
    }

    public function egressUsdPerGb(): float
    {
        return max(0, (float) config('media.b2_egress_usd_per_gb', 0.01));
    }

    public function storageCostUsd(int $bytes): float
    {
        return $this->bytesToGb($bytes) * $this->storageUsdPerGbMonth();
    }

    public function egressCostUsd(int $bytes): float
    {
        return $this->bytesToGb($bytes) * $this->egressUsdPerGb();
    }

    /** @return array{storage_usd: float, egress_usd: float, estimated_usd: float} */
    public function estimate(?UserStorageUsage $usage): array
    {
        $stored = (int) ($usage?->storage_used_bytes ?? 0);
        $egress = (int) ($usage?->streamed_bytes ?? 0)
            + (int) ($usage?->downloaded_bytes ?? 0)
            + (int) ($usage?->file_viewed_bytes ?? 0);

        $storageUsd = $this->storageCostUsd($stored);
        $egressUsd = $this->egressCostUsd($egress);

        return [
            'storage_usd' => round($storageUsd, 4),
            'egress_usd' => round($egressUsd, 4),
            'estimated_usd' => round($storageUsd + $egressUsd, 4),
        ];
    }

    public function estimateFromTotals(int $storedBytes, int $egressBytes): array
    {
        $storageUsd = $this->storageCostUsd($storedBytes);
        $egressUsd = $this->egressCostUsd($egressBytes);

        return [
            'storage_usd' => round($storageUsd, 4),
            'egress_usd' => round($egressUsd, 4),
            'estimated_usd' => round($storageUsd + $egressUsd, 4),
        ];
    }

    private function bytesToGb(int $bytes): float
    {
        if ($bytes <= 0) {
            return 0.0;
        }

        return $bytes / StorageBytes::GIB;
    }
}

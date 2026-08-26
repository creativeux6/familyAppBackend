<?php

namespace App\Modules\StoragePlans\Support;

final class StorageBytes
{
    public const GIB = 1073741824;

    public static function fromGib(float $gib): int
    {
        return (int) round($gib * self::GIB);
    }
}

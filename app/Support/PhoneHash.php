<?php

namespace App\Support;

class PhoneHash
{
    public static function hash(?string $phone): ?string
    {
        $normalized = self::normalize($phone);

        if ($normalized === null) {
            return null;
        }

        return hash('sha256', $normalized);
    }

    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $trimmed = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if ($trimmed === '' || $trimmed === '+') {
            return null;
        }

        if (! str_starts_with($trimmed, '+')) {
            $trimmed = '+'.$trimmed;
        }

        return $trimmed;
    }

    public static function isValidHash(string $hash): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/', $hash);
    }
}

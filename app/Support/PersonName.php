<?php

namespace App\Support;

final class PersonName
{
    /**
     * @return array{0: string, 1: string}
     */
    public static function split(?string $fullName): array
    {
        $fullName = trim((string) $fullName);

        if ($fullName === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/u', $fullName, 2) ?: [];

        return [
            trim((string) ($parts[0] ?? '')),
            trim((string) ($parts[1] ?? '')),
        ];
    }

    public static function display(?string $firstName, ?string $lastName): string
    {
        return trim(trim((string) $firstName).' '.trim((string) $lastName));
    }
}

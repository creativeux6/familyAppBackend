<?php

namespace App\Modules\FamilyTree\Services;

use App\Models\FamilyMember;
use Illuminate\Support\Str;

class MemberCodeService
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LENGTH = 7;

    public function generateUnique(): string
    {
        do {
            $code = 'M'.$this->randomSegment();
        } while (FamilyMember::withTrashed()->where('member_code', $code)->exists());

        return $code;
    }

    public function normalize(string $code): string
    {
        return Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    private function randomSegment(): string
    {
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }

        return $out;
    }
}

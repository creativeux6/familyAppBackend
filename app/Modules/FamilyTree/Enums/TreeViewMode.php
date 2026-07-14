<?php

namespace App\Modules\FamilyTree\Enums;

enum TreeViewMode: string
{
    case Blood = 'blood';
    case Inlaws = 'inlaws';
    case All = 'all';

    public static function fromRequest(?string $value): self
    {
        return match ($value) {
            self::Blood->value => self::Blood,
            self::Inlaws->value => self::Inlaws,
            default => self::All,
        };
    }
}

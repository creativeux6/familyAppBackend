<?php

namespace App\Support;

use Throwable;
use WeakMap;

/**
 * Attaches operator-only diagnostics to exceptions without changing the
 * client-facing API response body.
 *
 * @phpstan-type Diagnostic array{error_code: string, message: string, context: array<string, mixed>}
 */
final class AdminDiagnostic
{
    /** @var WeakMap<object, Diagnostic>|null */
    private static ?WeakMap $bag = null;

    /**
     * @param  array<string, mixed>  $context
     */
    public static function attach(
        Throwable $e,
        string $errorCode,
        string $message,
        array $context = [],
    ): void {
        self::bag()[$e] = [
            'error_code' => $errorCode,
            'message' => $message,
            'context' => $context,
        ];
    }

    /** @return Diagnostic|null */
    public static function for(Throwable $e): ?array
    {
        return self::bag()[$e] ?? null;
    }

    /** @return WeakMap<object, Diagnostic> */
    private static function bag(): WeakMap
    {
        return self::$bag ??= new WeakMap;
    }
}

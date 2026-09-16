<?php

namespace App\Logging;

use App\Modules\Admin\Services\SystemErrorLogService;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Mirrors ERROR+ Monolog records into system_error_logs for the admin Logs page.
 */
class SystemErrorLogHandler extends AbstractProcessingHandler
{
    private static bool $writing = false;

    public function __construct(int|string|Level $level = Level::Error, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (self::$writing) {
            return;
        }

        if ($record->level->value < Level::Error->value) {
            return;
        }

        self::$writing = true;

        try {
            if (! app()->bound(SystemErrorLogService::class)) {
                return;
            }

            app(SystemErrorLogService::class)->recordMonolog(
                level: $record->level->getName(),
                message: $record->message,
                context: $record->context,
                extra: $record->extra,
            );
        } catch (Throwable) {
            // Never break the original logger.
        } finally {
            self::$writing = false;
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Modules\Calendar\Services\CalendarService;
use Illuminate\Console\Command;

class SendCalendarNotificationsCommand extends Command
{
    protected $signature = 'calendar:send-notifications {--days=3 : Days before the event for upcoming reminders}';

    protected $description = 'Send upcoming and day-of calendar notifications';

    public function handle(CalendarService $calendarService): int
    {
        $days = (int) $this->option('days');

        $upcoming = $calendarService->sendUpcomingReminders($days);
        $today = $calendarService->sendTodayCelebrations();

        $this->info("Sent {$upcoming} upcoming reminder(s) and {$today} day-of celebration(s).");

        return self::SUCCESS;
    }
}

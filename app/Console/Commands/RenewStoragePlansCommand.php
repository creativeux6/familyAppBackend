<?php

namespace App\Console\Commands;

use App\Modules\StoragePlans\Services\PlanAssignmentService;
use Illuminate\Console\Command;

class RenewStoragePlansCommand extends Command
{
    protected $signature = 'storage:renew-plans';

    protected $description = 'Advance due storage plan billing dates (ends_at). Does not reset quota or usage.';

    public function handle(PlanAssignmentService $assignments): int
    {
        $count = $assignments->renewDueAssignments();
        $this->info("Renewed {$count} storage plan assignment(s).");

        return self::SUCCESS;
    }
}

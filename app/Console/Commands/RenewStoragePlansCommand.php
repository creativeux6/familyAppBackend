<?php

namespace App\Console\Commands;

use App\Modules\StoragePlans\Services\PlanAssignmentService;
use App\Modules\StoragePlans\Services\PlanBillingService;
use Illuminate\Console\Command;

class RenewStoragePlansCommand extends Command
{
    protected $signature = 'storage:renew-plans';

    protected $description = 'Advance due storage plan billing dates, roll usage periods, and retry failed payments.';

    public function handle(PlanAssignmentService $assignments, PlanBillingService $billing): int
    {
        $renewed = $assignments->renewDueAssignments();
        $this->info("Renewed {$renewed} storage plan assignment(s).");

        $retried = $billing->retryPastDueDue();
        $this->info("Processed {$retried} past-due payment retry(s).");

        return self::SUCCESS;
    }
}

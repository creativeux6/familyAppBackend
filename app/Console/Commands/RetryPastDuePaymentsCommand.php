<?php

namespace App\Console\Commands;

use App\Modules\StoragePlans\Services\PlanBillingService;
use Illuminate\Console\Command;

class RetryPastDuePaymentsCommand extends Command
{
    protected $signature = 'payments:retry-past-due';

    protected $description = 'Retry past-due plan charges and lock media after the grace window.';

    public function handle(PlanBillingService $billing): int
    {
        $processed = $billing->retryPastDueDue();
        $this->info("Processed {$processed} past-due assignment(s).");

        return self::SUCCESS;
    }
}

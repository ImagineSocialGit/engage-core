<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Actions\ScheduleDueCampaignAllocationRunsAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessDueCampaignAllocationsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $uniqueFor = 55;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'campaigns:due-recurring-allocations';
    }

    public function handle(
        ScheduleDueCampaignAllocationRunsAction $scheduleDueRuns,
    ): void {
        $scheduleDueRuns->handle();
    }
}
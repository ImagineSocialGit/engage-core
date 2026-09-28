<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Actions\ProcessCampaignAllocationRunAction;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessCampaignAllocationRunJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $runId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'campaigns:allocation-run:'.$this->runId;
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(
        ProcessCampaignAllocationRunAction $processRun,
    ): void {
        $processRun->handle($this->runId);
    }
}
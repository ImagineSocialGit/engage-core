<?php

namespace App\Modules\Campaigns\Jobs;

use App\Modules\Campaigns\Actions\StartCompletedCampaignAppendAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessCompletedCampaignAppendChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $appendId,
        public readonly int $afterId,
    ) {
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function handle(StartCompletedCampaignAppendAction $action): void
    {
        $action->processChunk($this->appendId, $this->afterId);
    }
}
<?php

namespace App\Modules\FlowRoutes\Jobs;

use App\Modules\FlowRoutes\Actions\ResumeContactFlowRouteProgressAction;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable as DispatchableQueueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResumeFlowRouteProgressJob implements ShouldQueue
{
    use DispatchableQueueable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $contactFlowRouteProgressId,
    ) {
        $this->onQueue((string) config('queue.flow_routes_queue', 'default'));
    }

    public function handle(ResumeContactFlowRouteProgressAction $resumeContactFlowRouteProgress): void
    {
        $progress = ContactFlowRouteProgress::query()
            ->find($this->contactFlowRouteProgressId);

        if (! $progress) {
            return;
        }

        $resumeContactFlowRouteProgress->handle($progress);
    }
}
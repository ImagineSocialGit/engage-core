<?php

namespace App\Modules\FlowRoutes\Jobs;

use App\Modules\FlowRoutes\Actions\ExecuteFlowRouteProgressUntilIdleAction;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

class ContinueFlowRouteProgressJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $backoff = 5;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $contactFlowRouteProgressId,
    ) {
        $queue = config('flow_routes.execution.continuation_queue', 'default');

        $this->onQueue(is_string($queue) && trim($queue) !== '' ? trim($queue) : 'default');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(5)
                ->expireAfter(300),
        ];
    }

    public function uniqueId(): string
    {
        return 'flow-route-progress:'.$this->contactFlowRouteProgressId;
    }

    public function handle(ExecuteFlowRouteProgressUntilIdleAction $executeFlowRouteProgressUntilIdle): void
    {
        $progress = ContactFlowRouteProgress::query()
            ->find($this->contactFlowRouteProgressId);

        if (! $progress instanceof ContactFlowRouteProgress || ! $progress->isActive()) {
            return;
        }

        $executeFlowRouteProgressUntilIdle->handle(
            progress: $progress,
            source: 'continuation_job',
        );
    }

    public function failed(?Throwable $exception): void
    {
        $progress = ContactFlowRouteProgress::query()
            ->find($this->contactFlowRouteProgressId);

        if (! $progress instanceof ContactFlowRouteProgress) {
            return;
        }

        $meta = $progress->meta ?? [];
        $continuation = $meta['immediate_execution_continuation'] ?? null;

        if (! is_array($continuation) || ($continuation['status'] ?? null) !== 'scheduled') {
            return;
        }

        $meta['immediate_execution_continuation'] = array_replace($continuation, [
            'status' => 'failed',
            'failed_at' => Carbon::now()->toISOString(),
            'exception_class' => $exception ? get_class($exception) : null,
            'exception_message' => $exception?->getMessage(),
        ]);

        $progress->forceFill(['meta' => $meta])->save();
    }
}
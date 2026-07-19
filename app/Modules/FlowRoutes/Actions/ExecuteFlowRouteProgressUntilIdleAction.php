<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Jobs\ContinueFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ExecuteFlowRouteProgressUntilIdleAction
{
    private const CONTINUATION_META_KEY = 'immediate_execution_continuation';

    private const CONTINUATION_HISTORY_META_KEY = 'immediate_execution_continuation_history';

    public function __construct(
        private readonly ExecuteCurrentFlowRoutePointAction $executeCurrentFlowRoutePoint,
    ) {}

    public function handle(
        ContactFlowRouteProgress $progress,
        string $source = 'inline',
    ): PointExecutionResult {
        $budget = $this->immediateExecutionBudget();
        $attempts = 0;
        $result = null;
        $continuationSequence = $this->continuationSequence($progress);

        do {
            $result = $this->executeCurrentFlowRoutePoint->handle($progress);
            $progress->refresh();
            $attempts++;
        } while ($attempts < $budget && $result->shouldAdvance() && $progress->isActive());

        $result ??= PointExecutionResult::blocked(
            reason: 'flow_route_progress_not_executed',
            meta: [
                'progress_id' => $progress->getKey(),
            ],
        );

        if ($attempts >= $budget && $result->shouldAdvance() && $progress->isActive()) {
            $this->persistAndScheduleContinuation(
                progress: $progress,
                result: $result,
                source: $source,
                budget: $budget,
                attempts: $attempts,
            );

            return $result;
        }

        $this->settleContinuation(
            progress: $progress,
            result: $result,
            source: $source,
            budget: $budget,
            attempts: $attempts,
            expectedSequence: $continuationSequence,
        );

        return $result;
    }

    private function persistAndScheduleContinuation(
        ContactFlowRouteProgress $progress,
        PointExecutionResult $result,
        string $source,
        int $budget,
        int $attempts,
    ): void {
        $expectedPointId = $this->nullableInt($progress->current_flow_route_point_id);

        DB::transaction(function () use ($progress, $result, $source, $budget, $attempts, $expectedPointId) {
            $lockedProgress = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->find($progress->getKey());

            if (! $lockedProgress instanceof ContactFlowRouteProgress
                || ! $lockedProgress->isActive()
                || $this->nullableInt($lockedProgress->current_flow_route_point_id) !== $expectedPointId
            ) {
                return;
            }

            $recordedAt = Carbon::now();
            $meta = $lockedProgress->meta ?? [];
            $previous = $meta[self::CONTINUATION_META_KEY] ?? [];

            if (! is_array($previous)) {
                $previous = [];
            }

            $payload = [
                'status' => 'scheduled',
                'sequence' => max(0, (int) ($previous['sequence'] ?? 0)) + 1,
                'scheduled_at' => $recordedAt->toISOString(),
                'source' => $source,
                'execution_budget' => $budget,
                'executions_in_slice' => $attempts,
                'flow_route_point_id' => $lockedProgress->current_flow_route_point_id,
                'progress_status' => $lockedProgress->status,
                'last_result' => $result->toMetaPayload(),
            ];

            $meta[self::CONTINUATION_META_KEY] = $payload;
            $meta[self::CONTINUATION_HISTORY_META_KEY] = $this->appendHistory(
                $meta[self::CONTINUATION_HISTORY_META_KEY] ?? [],
                $payload,
            );

            $lockedProgress->forceFill(['meta' => $meta])->save();

            ContinueFlowRouteProgressJob::dispatch($lockedProgress->getKey())
                ->afterCommit();
        });
    }

    private function settleContinuation(
        ContactFlowRouteProgress $progress,
        PointExecutionResult $result,
        string $source,
        int $budget,
        int $attempts,
        ?int $expectedSequence,
    ): void {
        if ($expectedSequence === null) {
            return;
        }

        DB::transaction(function () use ($progress, $result, $source, $budget, $attempts, $expectedSequence) {
            $lockedProgress = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->find($progress->getKey());

            if (! $lockedProgress instanceof ContactFlowRouteProgress) {
                return;
            }

            $meta = $lockedProgress->meta ?? [];
            $current = $meta[self::CONTINUATION_META_KEY] ?? null;

            if (! is_array($current) || (int) ($current['sequence'] ?? 0) !== $expectedSequence) {
                return;
            }

            $payload = array_replace($current, [
                'status' => 'settled',
                'settled_at' => Carbon::now()->toISOString(),
                'settled_by' => $source,
                'execution_budget' => $budget,
                'executions_in_final_slice' => $attempts,
                'flow_route_point_id' => $lockedProgress->current_flow_route_point_id,
                'progress_status' => $lockedProgress->status,
                'last_result' => $result->toMetaPayload(),
            ]);

            $meta[self::CONTINUATION_META_KEY] = $payload;
            $meta[self::CONTINUATION_HISTORY_META_KEY] = $this->appendHistory(
                $meta[self::CONTINUATION_HISTORY_META_KEY] ?? [],
                $payload,
            );

            $lockedProgress->forceFill(['meta' => $meta])->save();
        });
    }

    private function immediateExecutionBudget(): int
    {
        $configured = config('flow_routes.execution.immediate_execution_budget', 25);

        if (! is_numeric($configured)) {
            return 25;
        }

        return max(1, (int) $configured);
    }

    private function continuationSequence(ContactFlowRouteProgress $progress): ?int
    {
        $continuation = ($progress->meta ?? [])[self::CONTINUATION_META_KEY] ?? null;
        $sequence = is_array($continuation) ? ($continuation['sequence'] ?? null) : null;

        return is_numeric($sequence) ? (int) $sequence : null;
    }

    /**
     * @param mixed $history
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function appendHistory(mixed $history, array $payload): array
    {
        if (! is_array($history)) {
            $history = [];
        }

        $history[] = $payload;

        return array_slice($history, -50);
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
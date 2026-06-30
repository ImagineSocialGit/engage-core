<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class ResumeDueFlowRouteProgressAction
{
    public function __construct(
        private readonly ResumeContactFlowRouteProgressAction $resumeContactFlowRouteProgress,
    ) {}

    /**
     * @return array{
     *     checked: int,
     *     resumed: int,
     *     waiting: int,
     *     blocked: int,
     *     failed: int,
     *     completed: int,
     *     skipped: int
     * }
     */
    public function handle(?int $limit = null, ?CarbonInterface $now = null): array
    {
        $now = $now
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');

        $summary = [
            'checked' => 0,
            'resumed' => 0,
            'waiting' => 0,
            'blocked' => 0,
            'failed' => 0,
            'completed' => 0,
            'skipped' => 0,
        ];

        $query = ContactFlowRouteProgress::query()
            ->with('currentFlowRoutePoint.point')
            ->dueToResume($now)
            ->oldest('resume_at')
            ->oldest('id');

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $progress) {
            $summary['checked']++;

            $result = $this->resumeContactFlowRouteProgress->handle($progress, $now);

            match ($result->status) {
                PointExecutionResult::STATUS_COMPLETED => $summary['completed']++,
                PointExecutionResult::STATUS_WAITING => $summary['waiting']++,
                PointExecutionResult::STATUS_BLOCKED => $summary['blocked']++,
                PointExecutionResult::STATUS_FAILED => $summary['failed']++,
                PointExecutionResult::STATUS_SKIPPED => $summary['skipped']++,
                default => null,
            };

            if ($result->status !== PointExecutionResult::STATUS_BLOCKED) {
                $summary['resumed']++;
            }
        }

        return $summary;
    }
}
<?php

namespace App\Modules\FlowRoutes\Data\Events;

class FlowRouteExternalEventResumeResult
{
    /**
     * @var array<int, PointExecutionResult>
     */
    public array $executionResults = [];

    /**
     * @var array<int, int>
     */
    public array $matchedProgressIds = [];

    public int $checked = 0;

    public int $matched = 0;

    public int $resumed = 0;

    public int $ignored = 0;

    public function recordChecked(): void
    {
        $this->checked++;
    }

    public function recordIgnored(): void
    {
        $this->ignored++;
    }

    public function recordMatched(int $progressId): void
    {
        $this->matched++;
        $this->matchedProgressIds[] = $progressId;
    }

    public function recordResumed(PointExecutionResult $result): void
    {
        $this->resumed++;
        $this->executionResults[] = $result;
    }

    /**
     * @return array{
     *     checked: int,
     *     matched: int,
     *     resumed: int,
     *     ignored: int,
     *     matched_progress_ids: array<int, int>,
     *     execution_results: array<int, array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'checked' => $this->checked,
            'matched' => $this->matched,
            'resumed' => $this->resumed,
            'ignored' => $this->ignored,
            'matched_progress_ids' => $this->matchedProgressIds,
            'execution_results' => array_map(
                fn (PointExecutionResult $result) => $result->toMetaPayload(),
                $this->executionResults,
            ),
        ];
    }
}
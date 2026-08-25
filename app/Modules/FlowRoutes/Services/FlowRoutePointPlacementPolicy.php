<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FlowRoutePointPlacementPolicy
{
    public const VIOLATION_TERMINAL_WAIT = 'terminal_wait';
    public const VIOLATION_NON_TERMINAL_STATUS_CHANGE = 'non_terminal_status_change';
    public const VIOLATION_DECISION_TARGET_MISSING = 'decision_target_missing';
    public const VIOLATION_DECISION_TARGET_NOT_FORWARD = 'decision_target_not_forward';

    /**
     * @param Collection<int, FlowRoutePoint> $points
     */
    public function assertValidSequence(Collection $points, string $operation = 'save'): void
    {
        $violation = $this->firstViolation($points);

        if ($violation === null) {
            return;
        }

        throw ValidationException::withMessages([
            'point_order' => $this->messageFor($violation, $operation),
        ]);
    }

    /**
     * @param Collection<int, FlowRoutePoint> $points
     */
    public function firstViolation(Collection $points): ?string
    {
        $points = $points->values();

        if ($points->isEmpty()) {
            return null;
        }

        $lastIndex = $points->count() - 1;
        $lastPoint = $points->get($lastIndex);

        if ($lastPoint?->type === FlowRoutePointType::Wait->value) {
            return self::VIOLATION_TERMINAL_WAIT;
        }

        foreach ($points as $index => $point) {
            if (
                $point->type === FlowRoutePointType::ChangeStatus->value
                && $index !== $lastIndex
            ) {
                return self::VIOLATION_NON_TERMINAL_STATUS_CHANGE;
            }
        }

        $indexesByKey = $points
            ->mapWithKeys(fn (FlowRoutePoint $point, int $index): array => [
                (string) $point->key => $index,
            ]);

        foreach ($points as $index => $point) {
            if ($point->type !== FlowRoutePointType::BranchEvaluate->value) {
                continue;
            }

            foreach ($this->decisionTargetKeys($point) as $targetKey) {
                $targetIndex = $indexesByKey->get($targetKey);

                if (! is_int($targetIndex)) {
                    return self::VIOLATION_DECISION_TARGET_MISSING;
                }

                if ($targetIndex <= $index) {
                    return self::VIOLATION_DECISION_TARGET_NOT_FORWARD;
                }
            }
        }

        return null;
    }

    /**
     * @param Collection<int, FlowRoutePoint> $currentPoints
     * @return Collection<int, FlowRoutePoint>
     */
    public function proposedAdditionOrder(
        Collection $currentPoints,
        FlowRoutePoint $newPoint,
    ): Collection {
        $currentPoints = $currentPoints->values();

        if ($currentPoints->isEmpty()) {
            return collect([$newPoint]);
        }

        if ($newPoint->type === FlowRoutePointType::BranchEvaluate->value) {
            $targetKeys = $this->decisionTargetKeys($newPoint);
            $firstTargetIndex = $currentPoints
                ->search(fn (FlowRoutePoint $point): bool => in_array(
                    (string) $point->key,
                    $targetKeys,
                    true,
                ));

            if ($firstTargetIndex !== false) {
                return $currentPoints
                    ->slice(0, $firstTargetIndex)
                    ->push($newPoint)
                    ->concat($currentPoints->slice($firstTargetIndex))
                    ->values();
            }
        }

        $lastPoint = $currentPoints->last();

        if ($newPoint->type === FlowRoutePointType::Wait->value) {
            return $currentPoints
                ->slice(0, -1)
                ->push($newPoint)
                ->push($lastPoint)
                ->values();
        }

        if ($lastPoint?->type === FlowRoutePointType::ChangeStatus->value) {
            return $currentPoints
                ->slice(0, -1)
                ->push($newPoint)
                ->push($lastPoint)
                ->values();
        }

        return $currentPoints->push($newPoint)->values();
    }

    private function messageFor(string $violation, string $operation): string
    {
        return match ($violation) {
            self::VIOLATION_TERMINAL_WAIT => match ($operation) {
                'remove' => "This Point can't be removed because it would leave Wait as the final Point. Add or move another Point after Wait first.",
                'move' => "This Point can't be moved because Wait can't be the final Point. Add or move another Point after Wait first.",
                'add' => "Wait can't be the only or final Point in a Route. Add another Point first so something can happen after the Wait.",
                default => "Wait can't be the final Point. Add or move another Point after Wait first.",
            },
            self::VIOLATION_NON_TERMINAL_STATUS_CHANGE => match ($operation) {
                'remove' => "This Point can't be removed because Change Status must remain the final Point in the Route.",
                'move' => "This Point can't be moved because Change Status must remain the final Point in the Route.",
                'add' => "This Point can't be added after Change Status. Change Status ends the Route and hands the contact off to what comes next.",
                default => 'Change Status must be the final Point in the Route because changing workflow status hands the contact off to what comes next.',
            },
            self::VIOLATION_DECISION_TARGET_MISSING => match ($operation) {
                'remove' => "This Point can't be removed because a Decision still sends contacts to it. Update that Decision first.",
                'add' => 'The Decision must send contacts to an active Point in this Route.',
                default => 'Every Decision path must lead to an active Point in this Route.',
            },
            self::VIOLATION_DECISION_TARGET_NOT_FORWARD => match ($operation) {
                'move', 'reorder' => "That order would make a Decision send contacts backward. Move its destination after the Decision.",
                'add' => 'A Decision can only send contacts to a later Point in the Route.',
                default => 'Every Decision path must move forward to prevent Route loops.',
            },
            default => 'That Point order is not valid for this Route.',
        };
    }

    /** @return array<int, string> */
    private function decisionTargetKeys(FlowRoutePoint $point): array
    {
        $definition = is_array($point->definition) ? $point->definition : [];
        $keys = [];

        foreach ($definition['branches'] ?? [] as $branch) {
            if (! is_array($branch)) {
                continue;
            }

            $target = $branch['target_flow_route_point_key'] ?? null;

            if (is_string($target) && trim($target) !== '') {
                $keys[] = trim($target);
            }
        }

        $defaultTarget = $definition['default_target_flow_route_point_key'] ?? null;

        if (is_string($defaultTarget) && trim($defaultTarget) !== '') {
            $keys[] = trim($defaultTarget);
        }

        return array_values(array_unique($keys));
    }
}
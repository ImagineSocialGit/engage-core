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
            default => 'That Point order is not valid for this Route.',
        };
    }
}

<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FlowRoutePointAuthoringService
{
    public function __construct(
        private readonly AutomationPointAuthoringRegistry $authoring,
        private readonly FlowRoutePointPlacementPolicy $placementPolicy,
    ) {}

    /**
     * @param array<string, mixed> $input
     */
    public function create(
        FlowRoute $route,
        int $capabilityId,
        array $input,
    ): FlowRoutePoint {
        $this->ensureRouteCanBeChanged($route);

        $capability = FlowRouteCapability::query()
            ->active()
            ->findOrFail($capabilityId);

        $this->ensureCapabilityIsAuthorable($capability);
        $context = $this->authoringContext($route, capability: $capability);

        if (! $this->authoring->available((string) $capability->point_type, $context)) {
            throw ValidationException::withMessages([
                'capability_id' => 'That capability is not currently available for this Route.',
            ]);
        }

        return DB::transaction(function () use ($route, $capability, $input, $context): FlowRoutePoint {
            $definition = $this->authoring->buildDefinition($capability->point_type, $input, $context);
            $name = $this->authoring->pointName(
                $capability->point_type,
                $capability->name,
                $input,
                $definition,
                $context,
            );

            $point = new FlowRoutePoint([
                'flow_route_id' => $route->getKey(),
                'flow_route_capability_id' => $capability->getKey(),
                'key' => $this->uniquePointKey($route, $name),
                'type' => $capability->point_type,
                'name' => $name,
                'description' => null,
                'sort_order' => ((int) $route->flowRoutePoints()->max('sort_order')) + 10,
                'is_start' => false,
                'is_active' => true,
                'next_flow_route_point_id' => null,
                'definition' => $definition,
                'settings' => [],
                'cancel_conditions' => [],
                'source_version' => null,
                'is_customized' => true,
                'customized_at' => now(),
                'meta' => [
                    'authoring' => [
                        'source' => 'crm',
                        'created_at' => now()->toISOString(),
                    ],
                ],
            ]);

            $currentPoints = $route->activeFlowRoutePoints()
                ->orderBy('sort_order')
                ->get();
            $proposedOrder = $this->placementPolicy->proposedAdditionOrder($currentPoints, $point);

            $this->placementPolicy->assertValidSequence($proposedOrder, 'add');

            $point->save();

            $this->markRouteCustomized($route);
            $this->rebuildSequence($route, $proposedOrder);

            return $point->refresh();
        });
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(
        FlowRoute $route,
        FlowRoutePoint $point,
        array $input,
    ): FlowRoutePoint {
        $this->ensureRouteCanBeChanged($route);
        $this->ensurePointBelongsToRoute($route, $point);

        $capability = $point->capability;

        if (! $capability instanceof FlowRouteCapability) {
            $capability = FlowRouteCapability::query()
                ->active()
                ->where('point_type', $point->type)
                ->first();
        }

        if (! $capability instanceof FlowRouteCapability) {
            throw ValidationException::withMessages([
                'point' => 'This Point cannot be edited because its capability is unavailable.',
            ]);
        }

        $this->ensureCapabilityIsAuthorable($capability);

        return DB::transaction(function () use ($route, $point, $capability, $input): FlowRoutePoint {
            $context = $this->authoringContext($route, point: $point, capability: $capability);
            $definition = $this->authoring->buildDefinition($capability->point_type, $input, $context);

            $point->forceFill([
                'flow_route_capability_id' => $capability->getKey(),
                'type' => $capability->point_type,
                'name' => $this->authoring->pointName(
                    $capability->point_type,
                    $point->name ?: $capability->name,
                    $input,
                    $definition,
                    $context,
                ),
                'definition' => $definition,
                'is_customized' => true,
                'customized_at' => now(),
                'meta' => array_replace_recursive($point->meta ?? [], [
                    'authoring' => [
                        'source' => 'crm',
                        'updated_at' => now()->toISOString(),
                    ],
                ]),
            ])->save();

            $this->markRouteCustomized($route);

            return $point->refresh();
        });
    }

    public function deactivate(FlowRoute $route, FlowRoutePoint $point): void
    {
        $this->ensureRouteCanBeChanged($route);
        $this->ensurePointBelongsToRoute($route, $point);

        DB::transaction(function () use ($route, $point): void {
            $proposedOrder = $route->activeFlowRoutePoints()
                ->orderBy('sort_order')
                ->get()
                ->reject(fn (FlowRoutePoint $candidate): bool => $candidate->is($point))
                ->values();

            $this->placementPolicy->assertValidSequence($proposedOrder, 'remove');

            $point->forceFill([
                'is_active' => false,
                'is_start' => false,
                'next_flow_route_point_id' => null,
                'is_customized' => true,
                'customized_at' => now(),
                'meta' => array_replace_recursive($point->meta ?? [], [
                    'authoring' => [
                        'source' => 'crm',
                        'deactivated_at' => now()->toISOString(),
                    ],
                ]),
            ])->save();

            $this->markRouteCustomized($route);
            $this->rebuildSequence($route);
        });
    }

    public function move(
        FlowRoute $route,
        FlowRoutePoint $point,
        int $direction,
    ): void {
        $this->ensureRouteCanBeChanged($route);
        $this->ensurePointBelongsToRoute($route, $point);

        if (! in_array($direction, [-1, 1], true)) {
            throw ValidationException::withMessages([
                'point' => 'Point movement must be up or down.',
            ]);
        }

        DB::transaction(function () use ($route, $point, $direction): void {
            $points = $route->activeFlowRoutePoints()
                ->orderBy('sort_order')
                ->get();

            $currentIndex = $points->search(
                fn (FlowRoutePoint $candidate): bool => $candidate->is($point),
            );

            if ($currentIndex === false) {
                return;
            }

            $targetIndex = $currentIndex + $direction;

            if ($targetIndex < 0 || $targetIndex >= $points->count()) {
                return;
            }

            $ordered = $points->values()->all();
            [$ordered[$currentIndex], $ordered[$targetIndex]] = [$ordered[$targetIndex], $ordered[$currentIndex]];
            $proposedOrder = collect($ordered);

            $this->placementPolicy->assertValidSequence($proposedOrder, 'move');

            $this->markRouteCustomized($route);
            $this->rebuildSequence($route, $proposedOrder);
        });
    }

    /**
     * @param array<int, int> $pointIds
     */
    public function reorder(FlowRoute $route, array $pointIds): void
    {
        $this->ensureRouteCanBeChanged($route);

        $submittedIds = array_values(array_map('intval', $pointIds));
        $activePoints = $route->activeFlowRoutePoints()
            ->orderBy('sort_order')
            ->get();
        $activeIds = $activePoints->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $submittedSorted = $submittedIds;
        $activeSorted = $activeIds;
        sort($submittedSorted);
        sort($activeSorted);

        if ($submittedSorted !== $activeSorted) {
            throw ValidationException::withMessages([
                'point_ids' => 'The saved order must contain every active Point in this Route exactly once.',
            ]);
        }

        $pointsById = $activePoints->keyBy(fn (FlowRoutePoint $point): int => (int) $point->getKey());
        $ordered = collect($submittedIds)
            ->map(fn (int $pointId): FlowRoutePoint => $pointsById->get($pointId));

        $this->placementPolicy->assertValidSequence($ordered, 'reorder');

        DB::transaction(function () use ($route, $ordered): void {
            $this->markRouteCustomized($route);
            $this->rebuildSequence($route, $ordered);
        });
    }

    private function ensureRouteCanBeChanged(FlowRoute $route): void
    {
        $hasRunningProgress = $route->contactFlowRouteProgress()
            ->whereIn('status', ['active', 'waiting'])
            ->exists();

        if ($hasRunningProgress) {
            throw ValidationException::withMessages([
                'route' => 'This Route currently has active or waiting progress. Finish or cancel that progress before changing its Points.',
            ]);
        }
    }

    private function ensurePointBelongsToRoute(FlowRoute $route, FlowRoutePoint $point): void
    {
        if ((int) $point->flow_route_id !== (int) $route->getKey()) {
            throw ValidationException::withMessages([
                'point' => 'That Point does not belong to this Route.',
            ]);
        }
    }

    private function ensureCapabilityIsAuthorable(FlowRouteCapability $capability): void
    {
        $pointType = (string) $capability->point_type;

        if (! $this->authoring->has($pointType)) {
            throw ValidationException::withMessages([
                'capability_id' => 'That capability is not available in the Route editor.',
            ]);
        }

        if (data_get($capability->meta, 'runtime.handler_available_at_sync', true) === false) {
            throw ValidationException::withMessages([
                'capability_id' => 'That capability is not currently available at runtime.',
            ]);
        }
    }

    private function authoringContext(
        FlowRoute $route,
        ?FlowRoutePoint $point = null,
        ?FlowRouteCapability $capability = null,
    ): AutomationPointAuthoringContext {
        return new AutomationPointAuthoringContext(
            existingPointTypes: $route->activeFlowRoutePoints()
                ->orderBy('sort_order')
                ->pluck('type')
                ->map(fn (mixed $type): string => (string) $type)
                ->all(),
            container: $route,
            point: $point,
            capability: $capability,
        );
    }

    private function uniquePointKey(FlowRoute $route, string $name): string
    {
        $base = Str::slug($name, '_') ?: 'point';
        $candidate = $base;
        $suffix = 2;

        while ($route->flowRoutePoints()->where('key', $candidate)->exists()) {
            $candidate = $base.'_'.$suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function markRouteCustomized(FlowRoute $route): void
    {
        $route->forceFill([
            'is_customized' => true,
            'customized_at' => now(),
            'meta' => array_replace_recursive($route->meta ?? [], [
                'authoring' => [
                    'source' => 'crm',
                    'updated_at' => now()->toISOString(),
                ],
            ]),
        ])->save();
    }

    private function rebuildSequence(
        FlowRoute $route,
        ?Collection $orderedActivePoints = null,
    ): void {
        $activePoints = $orderedActivePoints ?? $route->activeFlowRoutePoints()
            ->orderBy('sort_order')
            ->get();

        $allPoints = $route->flowRoutePoints()
            ->orderBy('sort_order')
            ->get();

        $maxSortOrder = max(1000, (int) $allPoints->max('sort_order'));
        $offset = $maxSortOrder + 1000;

        foreach ($allPoints as $point) {
            $point->forceFill([
                'sort_order' => $point->sort_order + $offset,
                'is_start' => false,
                'next_flow_route_point_id' => null,
            ])->save();
        }

        foreach ($activePoints->values() as $index => $point) {
            $next = $activePoints->values()->get($index + 1);

            $point->forceFill([
                'sort_order' => ($index + 1) * 10,
                'is_start' => $index === 0,
                'next_flow_route_point_id' => $next?->getKey(),
            ])->save();
        }

        $inactivePoints = $allPoints
            ->where('is_active', false)
            ->values();

        foreach ($inactivePoints as $index => $point) {
            $point->forceFill([
                'sort_order' => 10000 + (($index + 1) * 10),
                'is_start' => false,
                'next_flow_route_point_id' => null,
            ])->save();
        }
    }
}

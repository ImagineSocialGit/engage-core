<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class FlowRouteBindingConflictDetector
{
    /** @return array<int, string> */
    public function exclusiveEffects(FlowRoute $route): array
    {
        $points = $route->relationLoaded('activeFlowRoutePoints')
            ? $route->activeFlowRoutePoints
            : $route->activeFlowRoutePoints()->get();

        return $points
            ->map(fn ($point): ?string => match ((string) $point->type) {
                FlowRoutePointType::ChangeStatus->value => 'contact.status',
                default => null,
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, array{route: FlowRoute, effects: array<int, string>}> */
    public function conflictsFor(FlowRoute $route): Collection
    {
        $effects = $this->exclusiveEffects($route);

        if ($effects === [] || ! filled($route->trigger_key)) {
            return collect();
        }

        $fingerprint = $this->entryConditionFingerprint($route);

        return FlowRouteTriggerBinding::query()
            ->active()
            ->global()
            ->forTrigger((string) $route->trigger_type, (string) $route->trigger_key)
            ->where('flow_route_id', '!=', $route->getKey())
            ->whereHas('flowRoute', fn ($query) => $query->active())
            ->with('flowRoute.activeFlowRoutePoints')
            ->get()
            ->map(function (FlowRouteTriggerBinding $binding) use ($effects, $fingerprint): ?array {
                $other = $binding->flowRoute;

                if (! $other instanceof FlowRoute
                    || $this->entryConditionFingerprint($other) !== $fingerprint
                ) {
                    return null;
                }

                $overlap = array_values(array_intersect($effects, $this->exclusiveEffects($other)));

                return $overlap === [] ? null : [
                    'route' => $other,
                    'effects' => $overlap,
                ];
            })
            ->filter()
            ->values();
    }

    public function assertNoConflicts(FlowRoute $route): void
    {
        $conflict = $this->conflictsFor($route)->first();

        if (! is_array($conflict) || ! $conflict['route'] instanceof FlowRoute) {
            return;
        }

        $effects = collect($conflict['effects'])
            ->map(fn (string $effect): string => match ($effect) {
                'contact.status' => 'contact status',
                default => str_replace('.', ' ', $effect),
            })
            ->implode(', ');

        throw ValidationException::withMessages([
            'flow_route' => "This cannot run with [{$conflict['route']->name}] because both respond to the same event and change the same {$effects}. Turn off, edit, or delete the other automation first.",
        ]);
    }

    /** @return Collection<int, array{first: FlowRoute, second: FlowRoute, effects: array<int, string>}> */
    public function activeConflicts(): Collection
    {
        $routes = FlowRouteTriggerBinding::query()
            ->active()
            ->global()
            ->whereHas('flowRoute', fn ($query) => $query->active())
            ->with('flowRoute.activeFlowRoutePoints')
            ->get()
            ->pluck('flowRoute')
            ->filter(fn ($route): bool => $route instanceof FlowRoute)
            ->unique(fn (FlowRoute $route): int => (int) $route->getKey())
            ->values();

        $conflicts = collect();

        foreach ($routes as $route) {
            foreach ($this->conflictsFor($route) as $conflict) {
                $other = $conflict['route'];

                if ((int) $route->getKey() >= (int) $other->getKey()) {
                    continue;
                }

                $conflicts->push([
                    'first' => $route,
                    'second' => $other,
                    'effects' => $conflict['effects'],
                ]);
            }
        }

        return $conflicts;
    }

    private function entryConditionFingerprint(FlowRoute $route): string
    {
        $conditions = data_get($route->meta, 'definition.entry_conditions', []);

        return json_encode(Arr::sortRecursive(is_array($conditions) ? $conditions : [])) ?: '[]';
    }
}
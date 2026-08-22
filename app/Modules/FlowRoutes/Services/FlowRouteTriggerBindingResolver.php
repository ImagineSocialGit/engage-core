<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use Illuminate\Database\Eloquent\Collection;

class FlowRouteTriggerBindingResolver
{
    /**
     * @return Collection<int, FlowRouteTriggerBinding>
     */
    public function selectedBindings(
        string $triggerType,
        ?string $triggerKey = null,
        ?string $contextType = null,
        int|string|null $contextId = null,
    ): Collection {
        $exactContextBindings = $this->bindingQuery($triggerType, $triggerKey)
            ->forContext($contextType, $contextId)
            ->get();

        if ($exactContextBindings->isNotEmpty()) {
            return $exactContextBindings;
        }

        if ($contextType === null && $contextId === null) {
            return $exactContextBindings;
        }

        return $this->bindingQuery($triggerType, $triggerKey)
            ->global()
            ->get();
    }

    /**
     * @return Collection<int, FlowRoute>
     */
    public function selectedFlowRoutes(
        string $triggerType,
        ?string $triggerKey = null,
        ?string $contextType = null,
        int|string|null $contextId = null,
    ): Collection {
        return $this->selectedBindings(
            triggerType: $triggerType,
            triggerKey: $triggerKey,
            contextType: $contextType,
            contextId: $contextId,
        )
            ->map(fn (FlowRouteTriggerBinding $binding): ?FlowRoute => $binding->flowRoute)
            ->filter(fn (?FlowRoute $flowRoute): bool => $flowRoute instanceof FlowRoute && (bool) $flowRoute->is_active)
            ->values();
    }

    public function selectedBinding(
        string $triggerType,
        ?string $triggerKey = null,
        ?string $contextType = null,
        int|string|null $contextId = null,
    ): ?FlowRouteTriggerBinding {
        return $this->selectedBindings(
            triggerType: $triggerType,
            triggerKey: $triggerKey,
            contextType: $contextType,
            contextId: $contextId,
        )->first();
    }

    public function selectedFlowRoute(
        string $triggerType,
        ?string $triggerKey = null,
        ?string $contextType = null,
        int|string|null $contextId = null,
    ): ?FlowRoute {
        $binding = $this->selectedBinding(
            triggerType: $triggerType,
            triggerKey: $triggerKey,
            contextType: $contextType,
            contextId: $contextId,
        );

        $flowRoute = $binding?->flowRoute;

        return $flowRoute instanceof FlowRoute && $flowRoute->is_active
            ? $flowRoute
            : null;
    }

    /** @return Collection<int, FlowRoute> */
    public function selectedFlowRoutesForContactStatus(ContactStatus|int $contactStatus): Collection
    {
        $status = $contactStatus instanceof ContactStatus
            ? $contactStatus
            : ContactStatus::query()->find($contactStatus);

        if (! $status instanceof ContactStatus) {
            return new Collection();
        }

        return $this->selectedFlowRoutes(
            triggerType: FlowRoute::TRIGGER_CONTACT_STATUS,
            triggerKey: $status->key,
        );
    }

    public function selectedFlowRouteForContactStatus(ContactStatus|int $contactStatus): ?FlowRoute
    {
        return $this->selectedFlowRoutesForContactStatus($contactStatus)->first();
    }

    public function selectedFlowRouteForTransition(ContactWorkflowStatusTransition $transition): ?FlowRoute
    {
        $routes = $this->selectedFlowRoutesForContactStatus($transition->toContactStatusId);

        if ($routes->isEmpty()) {
            return null;
        }

        $fromStatusKey = $transition->fromContactStatusId !== null
            ? ContactStatus::query()->whereKey($transition->fromContactStatusId)->value('key')
            : null;
        $fromStatusKey = is_string($fromStatusKey) ? $fromStatusKey : null;

        $selected = null;
        $selectedSpecificity = -1;

        foreach ($routes as $route) {
            if (! $route instanceof FlowRoute || ! $this->matchesTransition($route, $transition, $fromStatusKey)) {
                continue;
            }

            $specificity = $this->transitionSpecificity($route);

            if ($specificity > $selectedSpecificity) {
                $selected = $route;
                $selectedSpecificity = $specificity;
            }
        }

        return $selected;
    }

    public function selectedFlowRouteForAutomationEvent(
        string $eventKey,
        ?string $contextType = null,
        int|string|null $contextId = null,
    ): ?FlowRoute {
        return $this->selectedFlowRoute(
            triggerType: FlowRoute::TRIGGER_AUTOMATION_EVENT,
            triggerKey: $eventKey,
            contextType: $contextType,
            contextId: $contextId,
        );
    }

    private function matchesTransition(
        FlowRoute $route,
        ContactWorkflowStatusTransition $transition,
        ?string $fromStatusKey,
    ): bool {
        $qualifiers = $this->transitionQualifiers($route);

        return $this->matchesAllowedValue(
            actual: $fromStatusKey,
            allowed: $qualifiers['from_contact_status_keys'] ?? [],
        )
            && $this->matchesAllowedValue(
                actual: $transition->source,
                allowed: $qualifiers['sources'] ?? [],
            )
            && $this->matchesAllowedValue(
                actual: $transition->reason,
                allowed: $qualifiers['reasons'] ?? [],
            );
    }

    private function transitionSpecificity(FlowRoute $route): int
    {
        $qualifiers = $this->transitionQualifiers($route);

        return collect([
            $qualifiers['from_contact_status_keys'] ?? [],
            $qualifiers['sources'] ?? [],
            $qualifiers['reasons'] ?? [],
        ])->filter(fn (mixed $values): bool => is_array($values) && $values !== [])->count();
    }

    /** @return array<string, array<int, string>> */
    private function transitionQualifiers(FlowRoute $route): array
    {
        $value = data_get($route->meta, 'definition.transition', []);

        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach (['from_contact_status_keys', 'sources', 'reasons'] as $key) {
            $values = $value[$key] ?? [];

            if (! is_array($values)) {
                continue;
            }

            $normalized[$key] = array_values(array_filter(
                array_map(
                    fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
                    $values,
                ),
            ));
        }

        return $normalized;
    }

    /** @param array<int, string> $allowed */
    private function matchesAllowedValue(?string $actual, array $allowed): bool
    {
        if ($allowed === []) {
            return true;
        }

        return $actual !== null && in_array($actual, $allowed, true);
    }

    private function bindingQuery(string $triggerType, ?string $triggerKey)
    {
        return FlowRouteTriggerBinding::query()
            ->active()
            ->forTrigger($triggerType, $triggerKey)
            ->whereHas('flowRoute', fn ($query) => $query->active())
            ->with('flowRoute.activeFlowRoutePoints')
            ->orderByDesc('id');
    }
}
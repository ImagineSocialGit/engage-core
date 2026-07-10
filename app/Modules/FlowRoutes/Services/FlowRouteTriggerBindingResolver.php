<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
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

    /**
     * @return Collection<int, FlowRoute>
     */
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

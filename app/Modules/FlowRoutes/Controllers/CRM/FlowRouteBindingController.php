<?php

namespace App\Modules\FlowRoutes\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Requests\UpdateFlowRouteTriggerBindingRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FlowRouteBindingController extends Controller
{
    public function index(): View
    {
        return view('crm.flow-routes.bindings.index', [
            'contactStatusBindings' => $this->contactStatusBindings(),
            'automationEventBindings' => $this->automationEventBindings(),
        ]);
    }

    public function update(UpdateFlowRouteTriggerBindingRequest $request): RedirectResponse
    {
        $triggerType = (string) $request->validated('trigger_type');
        $triggerKey = (string) $request->validated('trigger_key');

        DB::transaction(function () use ($request, $triggerType, $triggerKey) {
            if ($triggerType === FlowRoute::TRIGGER_CONTACT_STATUS) {
                $this->updateContactStatusBinding(
                    triggerKey: $triggerKey,
                    flowRouteId: $request->integer('flow_route_id') ?: null,
                );

                return;
            }

            if ($triggerType === FlowRoute::TRIGGER_AUTOMATION_EVENT) {
                $this->updateAutomationEventBindings(
                    triggerKey: $triggerKey,
                    flowRouteIds: $this->selectedFlowRouteIds($request->validated('flow_route_ids', [])),
                );
            }
        });

        return redirect()
            ->route('crm.flow-routes.bindings.index')
            ->with('status', 'Route trigger bindings updated.');
    }

    private function updateContactStatusBinding(string $triggerKey, ?int $flowRouteId): void
    {
        $this->activeGlobalBindings(FlowRoute::TRIGGER_CONTACT_STATUS, $triggerKey)
            ->update([
                'is_active' => false,
            ]);

        if ($flowRouteId === null) {
            return;
        }

        $this->activateBinding(
            triggerType: FlowRoute::TRIGGER_CONTACT_STATUS,
            triggerKey: $triggerKey,
            flowRouteId: $flowRouteId,
        );
    }

    /**
     * @param array<int, int> $flowRouteIds
     */
    private function updateAutomationEventBindings(string $triggerKey, array $flowRouteIds): void
    {
        $activeBindings = $this->activeGlobalBindings(FlowRoute::TRIGGER_AUTOMATION_EVENT, $triggerKey);

        if ($flowRouteIds === []) {
            $activeBindings->update([
                'is_active' => false,
            ]);

            return;
        }

        $activeBindings
            ->whereNotIn('flow_route_id', $flowRouteIds)
            ->update([
                'is_active' => false,
            ]);

        foreach ($flowRouteIds as $flowRouteId) {
            $this->activateBinding(
                triggerType: FlowRoute::TRIGGER_AUTOMATION_EVENT,
                triggerKey: $triggerKey,
                flowRouteId: $flowRouteId,
            );
        }
    }

    private function activateBinding(
        string $triggerType,
        string $triggerKey,
        int $flowRouteId,
    ): FlowRouteTriggerBinding {
        $binding = FlowRouteTriggerBinding::query()->firstOrNew([
            'trigger_type' => $triggerType,
            'trigger_key' => $triggerKey,
            'flow_route_id' => $flowRouteId,
            'context_type' => null,
            'context_id' => null,
        ]);

        $binding->forceFill([
            'is_active' => true,
            'meta' => array_replace_recursive($binding->meta ?? [], [
                'selection' => [
                    'source' => 'crm',
                    'selected_at' => now()->toISOString(),
                ],
            ]),
        ])->save();

        return $binding;
    }

    private function activeGlobalBindings(string $triggerType, string $triggerKey)
    {
        return FlowRouteTriggerBinding::query()
            ->active()
            ->forTrigger($triggerType, $triggerKey)
            ->global();
    }

    /**
     * @return Collection<int, array{
     *     status: ContactStatus,
     *     available_routes: Collection<int, FlowRoute>,
     *     selected_route_id: int|null,
     *     active_binding_count: int
     * }>
     */
    private function contactStatusBindings(): Collection
    {
        return ContactStatus::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (ContactStatus $status): array {
                $availableRoutes = FlowRoute::query()
                    ->active()
                    ->forTrigger(FlowRoute::TRIGGER_CONTACT_STATUS, $status->key)
                    ->orderBy('name')
                    ->get();

                $activeBindings = FlowRouteTriggerBinding::query()
                    ->active()
                    ->forTrigger(FlowRoute::TRIGGER_CONTACT_STATUS, $status->key)
                    ->global()
                    ->whereHas('flowRoute', fn ($query) => $query->active())
                    ->with('flowRoute')
                    ->orderByDesc('id')
                    ->get();

                return [
                    'status' => $status,
                    'available_routes' => $availableRoutes,
                    'selected_route_id' => $activeBindings->first()?->flow_route_id,
                    'active_binding_count' => $activeBindings->count(),
                ];
            });
    }

    /**
     * @return Collection<int, array{
     *     event_key: string,
     *     available_routes: Collection<int, FlowRoute>,
     *     selected_route_ids: array<int, int>
     * }>
     */
    private function automationEventBindings(): Collection
    {
        return FlowRoute::query()
            ->active()
            ->where('trigger_type', FlowRoute::TRIGGER_AUTOMATION_EVENT)
            ->whereNotNull('trigger_key')
            ->select('trigger_key')
            ->distinct()
            ->orderBy('trigger_key')
            ->pluck('trigger_key')
            ->filter(fn (mixed $triggerKey): bool => is_string($triggerKey) && trim($triggerKey) !== '')
            ->values()
            ->map(function (string $eventKey): array {
                $availableRoutes = FlowRoute::query()
                    ->active()
                    ->forTrigger(FlowRoute::TRIGGER_AUTOMATION_EVENT, $eventKey)
                    ->orderBy('name')
                    ->get();

                $selectedRouteIds = FlowRouteTriggerBinding::query()
                    ->active()
                    ->forTrigger(FlowRoute::TRIGGER_AUTOMATION_EVENT, $eventKey)
                    ->global()
                    ->whereHas('flowRoute', fn ($query) => $query->active())
                    ->pluck('flow_route_id')
                    ->map(fn (mixed $flowRouteId): int => (int) $flowRouteId)
                    ->all();

                return [
                    'event_key' => $eventKey,
                    'available_routes' => $availableRoutes,
                    'selected_route_ids' => $selectedRouteIds,
                ];
            });
    }

    /**
     * @param mixed $flowRouteIds
     * @return array<int, int>
     */
    private function selectedFlowRouteIds(mixed $flowRouteIds): array
    {
        if (! is_array($flowRouteIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $flowRouteId): ?int => is_numeric($flowRouteId) ? (int) $flowRouteId : null,
            $flowRouteIds,
        ))));
    }
}

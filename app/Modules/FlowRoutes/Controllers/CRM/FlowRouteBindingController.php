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
use Illuminate\Support\Str;

class FlowRouteBindingController extends Controller
{
    public function index(): View
    {
        $contactStatuses = $this->activeContactStatuses();
        $statusNamesByKey = $contactStatuses
            ->mapWithKeys(fn (ContactStatus $status): array => [$status->key => $status->name])
            ->all();

        $automationEventBindings = $this->automationEventBindings($statusNamesByKey);
        $automationEventGroups = $automationEventBindings
            ->groupBy('module_key')
            ->map(function (Collection $rows, string $moduleKey): array {
                return [
                    'key' => $moduleKey,
                    'label' => (string) ($rows->first()['module_label'] ?? Str::headline($moduleKey)),
                    'events' => $rows->values(),
                ];
            })
            ->values();

        return view('crm.flow-routes.bindings.index', [
            'contactLabel' => [
                'singular' => (string) config('contacts.labels.singular', 'lead'),
                'plural' => (string) config('contacts.labels.plural', 'leads'),
            ],
            'contactStatusBindings' => $this->contactStatusBindings($contactStatuses, $statusNamesByKey),
            'automationEventGroups' => $automationEventGroups,
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
            ->with('status', 'Automatic follow-ups updated.');
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
     * @return Collection<int, ContactStatus>
     */
    private function activeContactStatuses(): Collection
    {
        return ContactStatus::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param Collection<int, ContactStatus> $contactStatuses
     * @param array<string, string> $statusNamesByKey
     * @return Collection<int, array{
     *     status: ContactStatus,
     *     available_routes: Collection<int, array<string, mixed>>,
     *     selected_route_id: int|null,
     *     selected_route: array<string, mixed>|null,
     *     active_binding_count: int
     * }>
     */
    private function contactStatusBindings(Collection $contactStatuses, array $statusNamesByKey): Collection
    {
        return $contactStatuses
            ->map(function (ContactStatus $status) use ($statusNamesByKey): array {
                $availableRoutes = FlowRoute::query()
                    ->active()
                    ->forTrigger(FlowRoute::TRIGGER_CONTACT_STATUS, $status->key)
                    ->with(['activeFlowRoutePoints'])
                    ->orderBy('name')
                    ->get()
                    ->map(fn (FlowRoute $route): array => $this->routeOption($route, $statusNamesByKey));

                $activeBindings = FlowRouteTriggerBinding::query()
                    ->active()
                    ->forTrigger(FlowRoute::TRIGGER_CONTACT_STATUS, $status->key)
                    ->global()
                    ->whereHas('flowRoute', fn ($query) => $query->active())
                    ->with('flowRoute')
                    ->orderByDesc('id')
                    ->get();

                $selectedRouteId = $activeBindings->first()?->flow_route_id;

                return [
                    'status' => $status,
                    'available_routes' => $availableRoutes,
                    'selected_route_id' => $selectedRouteId,
                    'selected_route' => $selectedRouteId
                        ? $availableRoutes->firstWhere('id', (int) $selectedRouteId)
                        : null,
                    'active_binding_count' => $activeBindings->count(),
                ];
            });
    }

    /**
     * @param array<string, string> $statusNamesByKey
     * @return Collection<int, array{
     *     event_key: string,
     *     module_key: string,
     *     module_label: string,
     *     label: string,
     *     description: string,
     *     available_routes: Collection<int, array<string, mixed>>,
     *     selected_route_ids: array<int, int>
     * }>
     */
    private function automationEventBindings(array $statusNamesByKey): Collection
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
            ->map(function (string $eventKey) use ($statusNamesByKey): array {
                $availableRoutes = FlowRoute::query()
                    ->active()
                    ->forTrigger(FlowRoute::TRIGGER_AUTOMATION_EVENT, $eventKey)
                    ->with(['activeFlowRoutePoints'])
                    ->orderBy('name')
                    ->get()
                    ->map(fn (FlowRoute $route): array => $this->routeOption($route, $statusNamesByKey));

                $selectedRouteIds = FlowRouteTriggerBinding::query()
                    ->active()
                    ->forTrigger(FlowRoute::TRIGGER_AUTOMATION_EVENT, $eventKey)
                    ->global()
                    ->whereHas('flowRoute', fn ($query) => $query->active())
                    ->pluck('flow_route_id')
                    ->map(fn (mixed $flowRouteId): int => (int) $flowRouteId)
                    ->all();

                $eventDisplay = $this->automationEventDisplay($eventKey);

                return [
                    'event_key' => $eventKey,
                    'module_key' => $eventDisplay['module_key'],
                    'module_label' => $eventDisplay['module_label'],
                    'label' => $eventDisplay['label'],
                    'description' => $eventDisplay['description'],
                    'available_routes' => $availableRoutes,
                    'selected_route_ids' => $selectedRouteIds,
                ];
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function routeOption(FlowRoute $route, array $statusNamesByKey): array
    {
        $points = $route->activeFlowRoutePoints
            ->sortBy('sort_order')
            ->values();

        return [
            'id' => (int) $route->id,
            'name' => (string) $route->name,
            'description' => (string) ($route->description ?: data_get($route->meta, 'description', '')),
            'summary_points' => $points
                ->map(fn ($routePoint): string => $this->routePointSummary($routePoint, $statusNamesByKey))
                ->filter()
                ->values()
                ->all(),
            'internal' => [
                'key' => (string) $route->key,
                'version' => (int) $route->version,
                'owner_group' => $route->owner_group ? (string) $route->owner_group : null,
            ],
        ];
    }

    /**
     * @param mixed $routePoint
     */
    private function routePointSummary($routePoint, array $statusNamesByKey): string
    {
        $type = (string) ($routePoint->type ?? '');
        $definition = is_array($routePoint->definition) ? $routePoint->definition : [];

        return match ($type) {
            'change_status' => $this->changeStatusSummary($definition, $statusNamesByKey),
            'enroll_campaign' => 'Start the '.$this->humanConfigLabel((string) data_get($definition, 'campaign_key', 'selected')).' follow-up.',
            'cancel_campaign' => 'Stop the '.$this->humanConfigLabel((string) data_get($definition, 'campaign_key', 'selected')).' follow-up.',
            'create_task' => 'Create task: '.(string) data_get($definition, 'title', $routePoint->name),
            'send_message' => 'Send a message.',
            'wait' => 'Wait before continuing.',
            'event_wait' => 'Wait until '.$this->humanAutomationEvent((string) data_get($definition, 'event_key', 'the next activity')).'.',
            'condition', 'branch_evaluate' => 'Check conditions before continuing.',
            'noop' => 'No action.',
            default => (string) ($routePoint->description ?: $routePoint->name),
        };
    }

    private function changeStatusSummary(array $definition, array $statusNamesByKey): string
    {
        $statusKey = (string) data_get($definition, 'contact_status_key', '');

        if ($statusKey === '') {
            return 'Update the status.';
        }

        return 'Move the '.config('contacts.labels.singular', 'lead').' to '.($statusNamesByKey[$statusKey] ?? Str::headline($statusKey)).'.';
    }

    /**
     * @return array{module_key: string, module_label: string, label: string, description: string}
     */
    private function automationEventDisplay(string $eventKey): array
    {
        return match ($eventKey) {
            'webinar.registered' => [
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'label' => 'When someone registers for a webinar',
                'description' => 'Choose what should happen after a lead registers.',
            ],
            'webinar.cancelled' => [
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'label' => 'When someone cancels a webinar registration',
                'description' => 'Choose what should happen after a lead cancels.',
            ],
            'webinar.attended' => [
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'label' => 'When someone attends a webinar',
                'description' => 'Choose what should happen after a lead attends.',
            ],
            'webinar.missed' => [
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'label' => 'When someone misses a webinar',
                'description' => 'Choose what should happen after a lead misses the live event.',
            ],
            'webinar.ended' => [
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
                'label' => 'When a webinar ends',
                'description' => 'Choose what should happen after a webinar ends.',
            ],
            default => [
                'module_key' => Str::before($eventKey, '.') ?: 'other',
                'module_label' => Str::headline(Str::before($eventKey, '.') ?: 'Other'),
                'label' => 'When '.Str::of($eventKey)->replace(['.', '_'], ' ')->lower(),
                'description' => 'Choose what should happen next.',
            ],
        };
    }

    private function humanAutomationEvent(string $eventKey): string
    {
        return match ($eventKey) {
            'webinar.attended' => 'someone attends a webinar',
            'webinar.missed' => 'someone misses a webinar',
            'webinar.registered' => 'someone registers for a webinar',
            'webinar.cancelled' => 'someone cancels a webinar registration',
            'webinar.ended' => 'a webinar ends',
            default => Str::of($eventKey)->replace(['.', '_'], ' ')->lower()->toString(),
        };
    }

    private function humanConfigLabel(string $value): string
    {
        if ($value === '') {
            return 'selected';
        }

        return Str::of($value)
            ->replace(['_', '-'], ' ')
            ->headline()
            ->lower()
            ->toString();
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

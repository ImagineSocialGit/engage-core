<?php

namespace App\Support\ModuleIntegrations\Scheduling\FlowRoutes;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Services\FlowRouteAuthoringLinkBuilder;
use App\Modules\Scheduling\Automation\AppointmentAutomationTriggerAuthoringContributor;
use App\Modules\Scheduling\Models\BookableService;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentAfterBookingWorkspace;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class FlowRoutesAppointmentAfterBookingWorkspace implements AppointmentAfterBookingWorkspace
{
    private const GUIDED_ACTIONS = [
        'core.add_contact_tag' => [
            'module_key' => 'core',
            'label' => 'Add a tag',
            'detail' => 'Add a reusable Contact tag after the appointment is scheduled.',
            'name_prefix' => 'Tag after',
        ],
        'flow_routes.change_status' => [
            'module_key' => 'workflow',
            'label' => 'Change status',
            'detail' => 'Move the Contact to a selected lifecycle status.',
            'name_prefix' => 'Change status after',
        ],
        'scheduling.create_appointment_task' => [
            'module_key' => 'tasks',
            'label' => 'Create follow-up task',
            'detail' => 'Create a Task tied to the Appointment with appointment-relative timing.',
            'name_prefix' => 'Follow up after',
        ],
    ];

    public function __construct(
        private readonly AutomationCapabilityRegistry $capabilities,
        private readonly ModuleManager $modules,
        private readonly FlowRouteAuthoringLinkBuilder $authoringLinks,
    ) {}

    public function read(): array
    {
        $routes = $this->routes();
        $services = BookableService::query()
            ->where('status', BookableService::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return [
            'mode' => 'flow_routes',
            'global' => [
                'actions' => $this->actions(
                    service: null,
                    name: 'any appointment',
                ),
                'automations' => $this->globalAutomations($routes),
            ],
            'services' => $services
                ->map(fn (BookableService $service): array => [
                    'service' => $service,
                    'actions' => $this->actions(
                        service: $service,
                        name: (string) $service->name,
                    ),
                    'automations' => $this->serviceAutomations(
                        routes: $routes,
                        service: $service,
                    ),
                ])
                ->values()
                ->all(),
        ];
    }

    public function update(
        BookableService $service,
        array $input,
    ): void {
        throw ValidationException::withMessages([
            'after_booking' => 'Flow Routes owns after-booking automation while that module is enabled.',
        ]);
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     module_key: string,
     *     label: string,
     *     detail: string,
     *     url: string
     * }>
     */
    private function actions(
        ?BookableService $service,
        string $name,
    ): array {
        $definitions = $this->capabilities->definitions();
        $enabled = $this->modules->enabledKeysWithDependencies();
        $actions = [];

        foreach (self::GUIDED_ACTIONS as $capabilityKey => $definition) {
            $capability = $definitions[$capabilityKey] ?? null;

            if ($capability === null
                || ! $capability->isActive
                || ! in_array($definition['module_key'], $enabled, true)
            ) {
                continue;
            }

            $actions[] = [
                'key' => $capabilityKey,
                'module_key' => $definition['module_key'],
                'label' => $definition['label'],
                'detail' => $definition['detail'],
                'url' => $this->authoringLinks->createUrl(
                    triggerAuthoringKey: AppointmentAutomationTriggerAuthoringContributor::KEY,
                    triggerValues: $this->triggerValues($service),
                    name: $definition['name_prefix'].' '.$name,
                    kind: FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
                    starterCapabilityKey: $capabilityKey,
                ),
            ];
        }

        $actions[] = [
            'key' => 'flow_routes.custom',
            'module_key' => 'flow_routes',
            'label' => 'Build custom automation',
            'detail' => 'Use a Route for several actions, waits, decisions, messages, or other automation.',
            'url' => $this->authoringLinks->createUrl(
                triggerAuthoringKey: AppointmentAutomationTriggerAuthoringContributor::KEY,
                triggerValues: $this->triggerValues($service),
                name: 'After '.$name.' is scheduled',
                kind: FlowRoute::AUTHORING_KIND_ROUTE,
            ),
        ];

        return $actions;
    }

    /**
     * @return array<string, string|int>
     */
    private function triggerValues(?BookableService $service): array
    {
        return array_filter([
            'appointment_event_key' => AppointmentAutomationTriggerAuthoringContributor::EVENT_SCHEDULED,
            'bookable_service_id' => $service?->getKey(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return Collection<int, FlowRoute>
     */
    private function routes(): Collection
    {
        return FlowRoute::query()
            ->currentVersion()
            ->forAutomationEvent(AppointmentAutomationTriggerAuthoringContributor::EVENT_SCHEDULED)
            ->withCount('activeFlowRoutePoints')
            ->orderBy('name')
            ->get()
            ->reject(fn (FlowRoute $route): bool => $route->isArchivedFromAuthoring())
            ->values();
    }

    /**
     * @param Collection<int, FlowRoute> $routes
     * @return array<int, array<string, mixed>>
     */
    private function globalAutomations(Collection $routes): array
    {
        return $routes
            ->filter(fn (FlowRoute $route): bool => ! $this->serviceScope($route)['scoped'])
            ->map(fn (FlowRoute $route): array => $this->routeItem($route))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, FlowRoute> $routes
     * @return array<int, array<string, mixed>>
     */
    private function serviceAutomations(
        Collection $routes,
        BookableService $service,
    ): array {
        return $routes
            ->filter(function (FlowRoute $route) use ($service): bool {
                $scope = $this->serviceScope($route);

                return $scope['scoped']
                    && $scope['service_id'] === (int) $service->getKey();
            })
            ->map(fn (FlowRoute $route): array => $this->routeItem($route))
            ->values()
            ->all();
    }

    /**
     * @return array{scoped: bool, service_id: int|null}
     */
    private function serviceScope(FlowRoute $route): array
    {
        $conditions = data_get($route->meta, 'definition.entry_conditions', []);

        if (! is_array($conditions)) {
            return ['scoped' => false, 'service_id' => null];
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition)
                || ($condition['source'] ?? null) !== 'execution_meta'
                || ($condition['path'] ?? null) !== AppointmentAutomationTriggerAuthoringContributor::BOOKABLE_SERVICE_EVENT_PATH
            ) {
                continue;
            }

            $value = $condition['value'] ?? null;

            return [
                'scoped' => true,
                'service_id' => ($condition['operator'] ?? null) === 'equals'
                    && is_numeric($value)
                        ? (int) $value
                        : null,
            ];
        }

        return ['scoped' => false, 'service_id' => null];
    }

    /**
     * @return array<string, mixed>
     */
    private function routeItem(FlowRoute $route): array
    {
        return [
            'id' => (int) $route->getKey(),
            'name' => (string) $route->name,
            'kind' => $route->authoringKind(),
            'is_enabled' => (bool) $route->is_active,
            'step_count' => (int) $route->active_flow_route_points_count,
            'step_label' => $route->active_flow_route_points_count === 1 ? 'step' : 'steps',
            'url' => $this->authoringLinks->editUrl($route),
        ];
    }
}
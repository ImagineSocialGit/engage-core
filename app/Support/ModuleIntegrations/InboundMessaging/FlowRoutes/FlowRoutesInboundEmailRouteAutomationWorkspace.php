<?php

namespace App\Support\ModuleIntegrations\InboundMessaging\FlowRoutes;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Services\FlowRouteAuthoringLinkBuilder;
use App\Modules\InboundMessaging\Automation\InboundEmailRouteAutomationTriggerAuthoringContributor;
use App\Support\ModuleIntegrations\InboundMessaging\Contracts\InboundEmailRouteAutomationWorkspace;
use Illuminate\Support\Collection;

final class FlowRoutesInboundEmailRouteAutomationWorkspace implements InboundEmailRouteAutomationWorkspace
{
    public function __construct(
        private readonly FlowRouteAuthoringLinkBuilder $authoringLinks,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function readForRoutes(array $routes): array
    {
        $flowRoutes = $this->routes();
        $result = [];

        foreach ($routes as $route) {
            $key = trim((string) ($route['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $label = trim((string) ($route['label'] ?? $key));
            $isActive = (bool) ($route['is_active'] ?? false);

            $result[$key] = [
                'available' => true,
                'create_url' => $isActive
                    ? $this->authoringLinks->createUrl(
                        triggerAuthoringKey: InboundEmailRouteAutomationTriggerAuthoringContributor::KEY,
                        triggerValues: ['inbound_email_route_key' => $key],
                        name: 'After '.$label.' receives email',
                        kind: FlowRoute::AUTHORING_KIND_ROUTE,
                    )
                    : null,
                'automations' => $flowRoutes
                    ->filter(fn (FlowRoute $flowRoute): bool =>
                        $this->appliesToRoute($flowRoute, $key))
                    ->map(fn (FlowRoute $flowRoute): array =>
                        $this->routeItem($flowRoute, $key))
                    ->values()
                    ->all(),
            ];
        }

        return $result;
    }

    /** @return Collection<int, FlowRoute> */
    private function routes(): Collection
    {
        return FlowRoute::query()
            ->currentVersion()
            ->forAutomationEvent(InboundEmailRouteAutomationTriggerAuthoringContributor::EVENT_KEY)
            ->withCount('activeFlowRoutePoints')
            ->orderBy('name')
            ->get()
            ->reject(fn (FlowRoute $route): bool => $route->isArchivedFromAuthoring())
            ->values();
    }

    private function appliesToRoute(
        FlowRoute $route,
        string $routeKey,
    ): bool {
        $scope = $this->routeScope($route);

        return ! $scope['scoped'] || $scope['route_key'] === $routeKey;
    }

    /** @return array{scoped: bool, route_key: string|null} */
    private function routeScope(FlowRoute $route): array
    {
        $conditions = data_get($route->meta, 'definition.entry_conditions', []);

        if (! is_array($conditions)) {
            return ['scoped' => false, 'route_key' => null];
        }

        foreach ($conditions as $condition) {
            if (! is_array($condition)
                || ($condition['source'] ?? null) !== 'execution_meta'
                || ($condition['path'] ?? null)
                    !== InboundEmailRouteAutomationTriggerAuthoringContributor::ROUTE_KEY_EVENT_PATH
            ) {
                continue;
            }

            return [
                'scoped' => true,
                'route_key' => ($condition['operator'] ?? null) === 'equals'
                    ? trim((string) ($condition['value'] ?? '')) ?: null
                    : null,
            ];
        }

        return ['scoped' => false, 'route_key' => null];
    }

    /** @return array<string, mixed> */
    private function routeItem(
        FlowRoute $route,
        string $routeKey,
    ): array {
        $scope = $this->routeScope($route);

        return [
            'id' => (int) $route->getKey(),
            'name' => (string) $route->name,
            'kind' => $route->authoringKind(),
            'is_enabled' => (bool) $route->is_active,
            'step_count' => (int) $route->active_flow_route_points_count,
            'step_label' => (int) $route->active_flow_route_points_count === 1
                ? 'step'
                : 'steps',
            'scope' => $scope['scoped'] && $scope['route_key'] === $routeKey
                ? 'this_address'
                : 'all_addresses',
            'url' => $this->authoringLinks->editUrl($route),
        ];
    }
}
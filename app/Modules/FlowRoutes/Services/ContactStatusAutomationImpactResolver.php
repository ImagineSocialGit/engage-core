<?php

namespace App\Modules\FlowRoutes\Services;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;

class ContactStatusAutomationImpactResolver
{
    public function __construct(
        private readonly FlowRouteTriggerBindingResolver $triggerBindingResolver,
    ) {}

    /**
     * @return array{
     *     has_automation: bool,
     *     status_id: int|null,
     *     status_key: string|null,
     *     status_name: string|null,
     *     route_count: int,
     *     routes: array<int, array{id: int, key: string, name: string}>
     * }
     */
    public function forContactStatus(ContactStatus|int $contactStatus): array
    {
        $status = $contactStatus instanceof ContactStatus
            ? $contactStatus
            : ContactStatus::query()->find($contactStatus);

        if (! $status instanceof ContactStatus) {
            return [
                'has_automation' => false,
                'status_id' => null,
                'status_key' => null,
                'status_name' => null,
                'route_count' => 0,
                'routes' => [],
            ];
        }

        $routes = $this->triggerBindingResolver
            ->selectedFlowRoutesForContactStatus($status)
            ->map(fn (FlowRoute $route): array => [
                'id' => (int) $route->getKey(),
                'key' => (string) $route->key,
                'name' => (string) $route->name,
            ])
            ->values()
            ->all();

        return [
            'has_automation' => $routes !== [],
            'status_id' => (int) $status->getKey(),
            'status_key' => (string) $status->key,
            'status_name' => (string) $status->name,
            'route_count' => count($routes),
            'routes' => $routes,
        ];
    }
}

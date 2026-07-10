<?php

namespace App\Modules\FlowRoutes\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Services\FlowRoutePresentationResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

class FlowRouteController extends Controller
{
    public function __construct(
        private readonly FlowRoutePresentationResolver $presentation,
    ) {}

    public function index(): View
    {
        $presentedRoutes = FlowRoute::query()
            ->currentVersion()
            ->with([
                'activeFlowRoutePoints.capability',
                'activeTriggerBindings',
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (FlowRoute $route): array => $this->presentation->route($route));

        $routes = $presentedRoutes
            ->where('kind', 'route')
            ->values();

        $automaticActions = $presentedRoutes
            ->where('kind', 'automatic_action')
            ->groupBy('group_key')
            ->map(function (Collection $items): array {
                $events = $items
                    ->groupBy('trigger_key')
                    ->map(function (Collection $eventItems): array {
                        $first = $eventItems->first();
                        $assignedItems = $eventItems
                            ->filter(fn (array $item): bool => (int) $item['assignment_count'] > 0)
                            ->values();
                        $availableItems = $eventItems
                            ->filter(fn (array $item): bool => (int) $item['assignment_count'] === 0)
                            ->values();

                        return [
                            'key' => (string) ($first['trigger_key'] ?? 'other'),
                            'label' => (string) ($first['trigger_summary'] ?? 'Automatic activity'),
                            'assignment_query' => $first['assignment_query'] ?? [],
                            'assignment_anchor' => $first['assignment_anchor'] ?? null,
                            'assigned_items' => $assignedItems,
                            'available_items' => $availableItems,
                            'assigned_count' => $assignedItems->count(),
                            'available_count' => $availableItems->count(),
                        ];
                    })
                    ->sortBy('label')
                    ->values();

                return [
                    'key' => (string) ($items->first()['group_key'] ?? 'other'),
                    'label' => (string) ($items->first()['group_label'] ?? 'Other'),
                    'events' => $events,
                    'action_count' => $items->count(),
                    'assigned_count' => $items->where('assignment_count', '>', 0)->count(),
                ];
            })
            ->sortBy('label')
            ->values();

        return view('crm.flow-routes.index', [
            'routes' => $routes,
            'automaticActions' => $automaticActions,
            'routeSummary' => [
                'routes' => $routes->count(),
                'automatic_actions' => $automaticActions->sum(
                    fn (array $group): int => (int) $group['action_count'],
                ),
                'unassigned_routes' => $routes->where('assignment_count', 0)->count(),
            ],
        ]);
    }
}

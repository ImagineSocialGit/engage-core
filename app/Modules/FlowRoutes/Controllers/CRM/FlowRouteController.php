<?php

namespace App\Modules\FlowRoutes\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Requests\StoreFlowRouteRequest;
use App\Modules\FlowRoutes\Services\FlowRouteEditorCatalog;
use App\Modules\FlowRoutes\Services\FlowRoutePresentationResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class FlowRouteController extends Controller
{
    public function __construct(
        private readonly FlowRoutePresentationResolver $presentation,
        private readonly FlowRouteEditorCatalog $editorCatalog,
    ) {}

    public function index(Request $request): View
    {
        $routeModels = FlowRoute::query()
            ->currentVersion()
            ->with([
                'flowRoutePoints.capability',
                'activeFlowRoutePoints.capability',
                'activeTriggerBindings',
            ])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $presentedRoutes = $routeModels
            ->map(fn (FlowRoute $route): array => $this->presentation->route($route));

        $routes = $presentedRoutes
            ->where('kind', 'route')
            ->values();

        $routeEditors = $routeModels
            ->filter(fn (FlowRoute $route): bool => ($this->presentation->route($route)['kind'] ?? null) === 'route')
            ->mapWithKeys(function (FlowRoute $route): array {
                $points = $route->flowRoutePoints
                    ->where('is_active', true)
                    ->sortBy('sort_order')
                    ->values();

                return [
                    (int) $route->getKey() => [
                        'model' => $route,
                        'route' => $this->presentation->route($route),
                        'points' => $points,
                        'capabilities' => $this->editorCatalog->availableCapabilities($route),
                    ],
                ];
            });

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

        $requestedEditorId = $request->integer('edit_route');
        $contactStatuses = ContactStatus::query()
            ->active()
            ->ordered()
            ->get();
        $requestedStatusKey = trim((string) $request->query('status', ''));
        $createRouteStatus = $requestedStatusKey !== ''
            ? $contactStatuses->firstWhere('key', $requestedStatusKey)
            : null;

        return view('crm.flow-routes.index', [
            'routes' => $routes,
            'routeEditors' => $routeEditors,
            'editorOptions' => $this->editorCatalog->editorOptions(),
            'openRouteEditorId' => $routeEditors->has($requestedEditorId) ? $requestedEditorId : null,
            'openCreateRoute' => $request->boolean('create')
                || (string) $request->session()->getOldInput('_flow_route_create') === '1',
            'createRouteContactStatuses' => $contactStatuses,
            'createRouteStatusId' => $createRouteStatus?->getKey(),
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

    public function store(StoreFlowRouteRequest $request): RedirectResponse
    {
        $status = ContactStatus::query()
            ->active()
            ->findOrFail($request->contactStatusId());

        $route = FlowRoute::query()->create([
            'key' => 'crm_route_'.Str::lower((string) Str::uuid()),
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'client',
            'name' => $request->routeName(),
            'description' => $request->routeDescription(),
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => (string) $status->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => true,
            'customized_at' => now(),
            'meta' => [
                'authoring' => [
                    'source' => 'crm',
                ],
            ],
        ]);

        return redirect()
            ->route('crm.flow-routes.index', ['edit_route' => $route->getKey()])
            ->with('status', 'Route created. Add its Points, then choose it in Assignments when it is ready to run.');
    }
}
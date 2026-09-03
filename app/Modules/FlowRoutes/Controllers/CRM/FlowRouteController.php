<?php

namespace App\Modules\FlowRoutes\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Automation\CoreAutomationTriggerAuthoringContributor;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Requests\StoreFlowRouteRequest;
use App\Modules\FlowRoutes\Services\FlowRouteBindingConflictDetector;
use App\Modules\FlowRoutes\Services\FlowRouteEditorCatalog;
use App\Modules\FlowRoutes\Services\FlowRoutePresentationResolver;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FlowRouteController extends Controller
{
    public function __construct(
        private readonly FlowRoutePresentationResolver $presentation,
        private readonly FlowRouteEditorCatalog $editorCatalog,
        private readonly AutomationTriggerAuthoringRegistry $triggerAuthoring,
        private readonly FlowRouteBindingConflictDetector $conflicts,
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
            ->orderBy('name')
            ->get()
            ->reject(fn (FlowRoute $route): bool => $route->isArchivedFromAuthoring())
            ->values();

        $presentedRoutes = $routeModels->map(function (FlowRoute $route): array {
            $presented = $this->presentation->route($route);
            $presented['conflict_names'] = $presented['is_enabled']
                ? $this->conflicts->conflictsFor($route)
                    ->pluck('route.name')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all()
                : [];

            return $presented;
        });

        $routes = $presentedRoutes
            ->where('kind', FlowRoute::AUTHORING_KIND_ROUTE)
            ->values();

        $automaticBehaviors = $presentedRoutes
            ->where('kind', FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR)
            ->sortBy(fn (array $route): string => ($route['group_label'] ?? '').($route['trigger_summary'] ?? '').$route['name'])
            ->values();

        $presentedById = $presentedRoutes->keyBy('id');
        $routeEditors = $routeModels->mapWithKeys(function (FlowRoute $route) use ($presentedById): array {
            $points = $route->flowRoutePoints
                ->where('is_active', true)
                ->sortBy('sort_order')
                ->values();

            return [
                (int) $route->getKey() => [
                    'model' => $route,
                    'route' => $presentedById->get((int) $route->getKey()),
                    'points' => $points,
                    'capabilities' => $this->editorCatalog->availableCapabilities($route),
                ],
            ];
        });

        $requestedEditorId = $request->integer('edit_route');
        $requestedStatusKey = trim((string) $request->query('status', ''));
        $createTriggers = $this->triggerAuthoring->presentation();
        $availableTriggerKeys = collect($createTriggers)
            ->pluck('key')
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->values()
            ->all();
        $requestedTriggerKey = trim((string) $request->query('trigger_authoring_key', ''));

        if (! in_array($requestedTriggerKey, $availableTriggerKeys, true)) {
            $requestedTriggerKey = '';
        }

        $statusTrigger = collect($createTriggers)->firstWhere(
            'key',
            CoreAutomationTriggerAuthoringContributor::CONTACT_STATUS,
        );
        $selectedStatusOption = $requestedStatusKey !== '' && is_array($statusTrigger)
            ? collect($statusTrigger['fields'][0]['options'] ?? [])->firstWhere('key', $requestedStatusKey)
            : null;
        $createStatusId = is_array($selectedStatusOption)
            ? ($selectedStatusOption['value'] ?? null)
            : null;
        $createTriggerValues = collect($createTriggers)
            ->flatMap(fn (array $trigger): array => $trigger['fields'] ?? [])
            ->mapWithKeys(function (array $field) use ($request): array {
                $name = (string) $field['name'];

                return [
                    $name => old($name, $request->query($name, '')),
                ];
            })
            ->all();
        $createTriggerValues['contact_status_id'] = old(
            'contact_status_id',
            $createStatusId ?? ($createTriggerValues['contact_status_id'] ?? ''),
        );
        $requestedCreateKind = (string) old(
            'authoring_kind',
            $request->query('create_kind', FlowRoute::AUTHORING_KIND_ROUTE),
        );

        if (! in_array($requestedCreateKind, FlowRoute::AUTHORING_KINDS, true)) {
            $requestedCreateKind = FlowRoute::AUTHORING_KIND_ROUTE;
        }

        $defaultTriggerKey = $requestedStatusKey !== ''
            ? CoreAutomationTriggerAuthoringContributor::CONTACT_STATUS
            : ($requestedTriggerKey !== ''
                ? $requestedTriggerKey
                : ($createTriggers[0]['key'] ?? null));
        $requestedAddCapabilityKey = trim((string) $request->query('add_capability', ''));
        $openAddCapabilityId = null;

        if ($requestedEditorId > 0 && $requestedAddCapabilityKey !== '' && $routeEditors->has($requestedEditorId)) {
            $editor = $routeEditors->get($requestedEditorId);
            $capability = is_array($editor)
                ? collect($editor['capabilities'] ?? [])->firstWhere('key', $requestedAddCapabilityKey)
                : null;
            $openAddCapabilityId = is_array($capability)
                ? (int) ($capability['id'] ?? 0)
                : null;

            if ($openAddCapabilityId === 0) {
                $openAddCapabilityId = null;
            }
        }

        return view('crm.flow-routes.index', [
            'routes' => $routes,
            'automaticBehaviors' => $automaticBehaviors,
            'routeEditors' => $routeEditors,
            'editorOptions' => $this->editorCatalog->editorOptions(),
            'openRouteEditorId' => $routeEditors->has($requestedEditorId) ? $requestedEditorId : null,
            'openAddCapabilityId' => $openAddCapabilityId,
            'openCreateRoute' => $request->boolean('create')
                || (string) $request->session()->getOldInput('_flow_route_create') === '1',
            'openCreateKind' => $requestedCreateKind,
            'createRouteTriggers' => $createTriggers,
            'createRouteTriggerKey' => old(
                'trigger_authoring_key',
                $defaultTriggerKey,
            ),
            'createRouteTriggerValues' => $createTriggerValues,
            'createRouteName' => (string) old(
                'name',
                trim((string) $request->query('create_name', '')),
            ),
            'createRouteStarterCapabilityKey' => (string) old(
                'starter_capability_key',
                trim((string) $request->query('starter_capability_key', '')),
            ),
            'routeSummary' => [
                'routes' => $routes->count(),
                'automatic_behaviors' => $automaticBehaviors->count(),
                'enabled' => $presentedRoutes->where('is_enabled', true)->count(),
                'conflicts' => $presentedRoutes->filter(fn (array $route): bool => $route['conflict_names'] !== [])->count(),
            ],
        ]);
    }

    public function store(StoreFlowRouteRequest $request): RedirectResponse
    {
        $selection = $this->triggerAuthoring->selection(
            $request->triggerAuthoringKey(),
            $request->validated(),
        );
        $kind = $request->authoringKind();

        $route = FlowRoute::query()->create([
            'key' => 'crm_route_'.Str::lower((string) Str::uuid()),
            'contact_status_id' => $selection->contactStatusId,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'client',
            'name' => $request->routeName(),
            'description' => $request->routeDescription(),
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => $selection->triggerType,
            'trigger_key' => $selection->triggerKey,
            'is_active' => false,
            'source_version' => null,
            'is_customized' => true,
            'customized_at' => now(),
            'meta' => [
                'authoring' => [
                    'source' => 'crm',
                    'kind' => $kind,
                    'trigger_authoring_key' => $request->triggerAuthoringKey(),
                ],
                'definition' => [
                    'entry_conditions' => $selection->entryConditions,
                ],
            ],
        ]);

        $label = $kind === FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR
            ? 'Automatic behavior'
            : 'Route';

        $redirect = ['edit_route' => $route->getKey()];
        $starterCapabilityKey = $request->starterCapabilityKey();

        if ($starterCapabilityKey !== null) {
            $redirect['add_capability'] = $starterCapabilityKey;
        }

        return redirect()
            ->route('crm.flow-routes.index', $redirect)
            ->with('status', "{$label} created. Add its action, review it, and turn it on when it is ready.");
    }
}
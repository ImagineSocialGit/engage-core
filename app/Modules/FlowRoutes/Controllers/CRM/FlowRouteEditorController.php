<?php

namespace App\Modules\FlowRoutes\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Requests\StoreFlowRoutePointRequest;
use App\Modules\FlowRoutes\Requests\UpdateFlowRoutePointOrderRequest;
use App\Modules\FlowRoutes\Requests\UpdateFlowRoutePointRequest;
use App\Modules\FlowRoutes\Services\FlowRoutePointAuthoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class FlowRouteEditorController extends Controller
{
    public function __construct(
        private readonly FlowRoutePointAuthoringService $authoring,
    ) {}

    public function show(FlowRoute $flowRoute): RedirectResponse
    {
        $this->ensureCurrentVersion($flowRoute);

        return redirect()->route('crm.flow-routes.index', [
            'edit_route' => $flowRoute->getKey(),
        ]);
    }

    public function storePoint(
        StoreFlowRoutePointRequest $request,
        FlowRoute $flowRoute,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);

        $this->authoring->create(
            route: $flowRoute,
            capabilityId: (int) $request->validated('capability_id'),
            input: $request->validated(),
        );

        return $this->redirectToEditor($flowRoute, 'Point added to Route.');
    }

    public function updatePoint(
        UpdateFlowRoutePointRequest $request,
        FlowRoute $flowRoute,
        FlowRoutePoint $flowRoutePoint,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);
        $this->ensurePointBelongsToRoute($flowRoute, $flowRoutePoint);

        $this->authoring->update(
            route: $flowRoute,
            point: $flowRoutePoint,
            input: $request->validated(),
        );

        return $this->redirectToEditor($flowRoute, 'Point updated.');
    }

    public function destroyPoint(
        FlowRoute $flowRoute,
        FlowRoutePoint $flowRoutePoint,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);
        $this->ensurePointBelongsToRoute($flowRoute, $flowRoutePoint);

        $this->authoring->deactivate($flowRoute, $flowRoutePoint);

        return $this->redirectToEditor($flowRoute, 'Point removed from the active Route.');
    }

    public function reorderPoints(
        UpdateFlowRoutePointOrderRequest $request,
        FlowRoute $flowRoute,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);

        $this->authoring->reorder(
            route: $flowRoute,
            pointIds: array_map('intval', $request->validated('point_ids')),
        );

        return $this->redirectToEditor($flowRoute, 'Point order saved.');
    }

    public function movePointUp(
        FlowRoute $flowRoute,
        FlowRoutePoint $flowRoutePoint,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);
        $this->ensurePointBelongsToRoute($flowRoute, $flowRoutePoint);

        $this->authoring->move($flowRoute, $flowRoutePoint, -1);

        return $this->redirectToEditor($flowRoute, 'Point moved.');
    }

    public function movePointDown(
        FlowRoute $flowRoute,
        FlowRoutePoint $flowRoutePoint,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);
        $this->ensurePointBelongsToRoute($flowRoute, $flowRoutePoint);

        $this->authoring->move($flowRoute, $flowRoutePoint, 1);

        return $this->redirectToEditor($flowRoute, 'Point moved.');
    }

    private function ensureCurrentVersion(FlowRoute $flowRoute): void
    {
        abort_unless($flowRoute->is_current_version, 404);
    }

    private function ensurePointBelongsToRoute(
        FlowRoute $flowRoute,
        FlowRoutePoint $flowRoutePoint,
    ): void {
        if ((int) $flowRoutePoint->flow_route_id !== (int) $flowRoute->getKey()) {
            throw ValidationException::withMessages([
                'point' => 'That Point does not belong to this Route.',
            ]);
        }
    }

    private function redirectToEditor(FlowRoute $flowRoute, string $message): RedirectResponse
    {
        return redirect()
            ->route('crm.flow-routes.index', [
                'edit_route' => $flowRoute->getKey(),
            ])
            ->with('status', $message);
    }
}

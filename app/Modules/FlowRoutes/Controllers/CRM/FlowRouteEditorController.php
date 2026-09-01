<?php

namespace App\Modules\FlowRoutes\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Requests\StoreFlowRoutePointRequest;
use App\Modules\FlowRoutes\Requests\UpdateFlowRouteLeadInDelayRequest;
use App\Modules\FlowRoutes\Requests\UpdateFlowRoutePointOrderRequest;
use App\Modules\FlowRoutes\Requests\UpdateFlowRoutePointRequest;
use App\Modules\FlowRoutes\Services\FlowRouteActivationService;
use App\Modules\FlowRoutes\Services\FlowRoutePointAuthoringService;
use App\Support\Guidance\FirstUseGuidance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FlowRouteEditorController extends Controller
{
    public function __construct(
        private readonly FlowRoutePointAuthoringService $authoring,
        private readonly FlowRouteActivationService $activation,
        private readonly FirstUseGuidance $guidance,
    ) {}

    public function show(FlowRoute $flowRoute): RedirectResponse
    {
        $this->ensureCurrentVersion($flowRoute);

        return redirect()->route('crm.flow-routes.index', [
            'edit_route' => $flowRoute->getKey(),
        ]);
    }

    public function updateEnabled(Request $request, FlowRoute $flowRoute): RedirectResponse
    {
        $this->ensureCurrentVersion($flowRoute);
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'return_to_editor' => ['nullable', 'boolean'],
        ]);

        if ((bool) $validated['enabled']) {
            $this->activation->enable($flowRoute);
            $message = 'Automation turned on.';
        } else {
            $this->activation->disable($flowRoute);
            $message = 'Automation turned off. Work already in progress is not canceled.';
        }

        $parameters = (bool) ($validated['return_to_editor'] ?? false)
            ? ['edit_route' => $flowRoute->getKey()]
            : [];

        return redirect()
            ->route('crm.flow-routes.index', $parameters)
            ->with('status', $message);
    }

    public function updateKind(Request $request, FlowRoute $flowRoute): RedirectResponse
    {
        $this->ensureCurrentVersion($flowRoute);
        $validated = $request->validate([
            'authoring_kind' => ['required', 'string', 'in:route,automatic_behavior'],
        ]);

        $this->activation->changeKind($flowRoute, (string) $validated['authoring_kind']);

        return $this->redirectToEditor(
            $flowRoute,
            $validated['authoring_kind'] === FlowRoute::AUTHORING_KIND_ROUTE
                ? 'This is now a Route. You can add more actions.'
                : 'This is now an Automatic behavior.',
        );
    }

    public function destroy(FlowRoute $flowRoute): RedirectResponse
    {
        $this->ensureCurrentVersion($flowRoute);
        $this->activation->archive($flowRoute);

        return redirect()
            ->route('crm.flow-routes.index')
            ->with('status', 'Automation deleted. Historical activity was preserved.');
    }

    public function storePoint(
        StoreFlowRoutePointRequest $request,
        FlowRoute $flowRoute,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);

        $input = $request->validated();
        $point = $this->authoring->create(
            route: $flowRoute,
            capabilityId: (int) $request->validated('capability_id'),
            input: $input,
        );

        $this->explainBusinessDaySettings($request, $input, $point);

        return $this->redirectToEditor(
            $flowRoute,
            $flowRoute->isAutomaticBehavior()
                ? 'Action added.'
                : 'Step added to Route.',
        );
    }

    public function updatePoint(
        UpdateFlowRoutePointRequest $request,
        FlowRoute $flowRoute,
        FlowRoutePoint $flowRoutePoint,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);
        $this->ensurePointBelongsToRoute($flowRoute, $flowRoutePoint);

        $input = $request->validated();
        $point = $this->authoring->update(
            route: $flowRoute,
            point: $flowRoutePoint,
            input: $input,
        );

        $this->explainBusinessDaySettings($request, $input, $point);

        return $this->redirectToEditor($flowRoute, 'Step updated.');
    }

    public function updateLeadInDelay(
        UpdateFlowRouteLeadInDelayRequest $request,
        FlowRoute $flowRoute,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);

        $input = $request->validated();
        $this->authoring->updateLeadInDelay(
            route: $flowRoute,
            input: $input,
        );

        $this->explainBusinessDaySettings($request, $input);

        return $this->redirectToEditor($flowRoute, 'First-step timing updated.');
    }

    public function destroyPoint(
        FlowRoute $flowRoute,
        FlowRoutePoint $flowRoutePoint,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);
        $this->ensurePointBelongsToRoute($flowRoute, $flowRoutePoint);

        $this->authoring->deactivate($flowRoute, $flowRoutePoint);

        return $this->redirectToEditor($flowRoute, 'Step removed from the active Route.');
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

        return $this->redirectToEditor($flowRoute, 'Step order saved.');
    }

    public function movePointUp(
        FlowRoute $flowRoute,
        FlowRoutePoint $flowRoutePoint,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);
        $this->ensurePointBelongsToRoute($flowRoute, $flowRoutePoint);

        $this->authoring->move($flowRoute, $flowRoutePoint, -1);

        return $this->redirectToEditor($flowRoute, 'Step moved.');
    }

    public function movePointDown(
        FlowRoute $flowRoute,
        FlowRoutePoint $flowRoutePoint,
    ): RedirectResponse {
        $this->ensureCurrentVersion($flowRoute);
        $this->ensurePointBelongsToRoute($flowRoute, $flowRoutePoint);

        $this->authoring->move($flowRoute, $flowRoutePoint, 1);

        return $this->redirectToEditor($flowRoute, 'Step moved.');
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
                'point' => 'That step does not belong to this Route.',
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

    /** @param array<string, mixed> $input */
    private function explainBusinessDaySettings(
        Request $request,
        array $input,
        ?FlowRoutePoint $point = null,
    ): void {
        if (($input['start_timing'] ?? null) === 'immediate'
            || ($input['wait_mode'] ?? 'duration') !== 'duration'
            || ($input['duration_unit'] ?? null) !== 'business_days'
            || ($point instanceof FlowRoutePoint && $point->type !== FlowRoutePointType::Wait->value)
        ) {
            return;
        }

        $this->guidance->flashOnce(
            request: $request,
            key: 'business_days',
            guidance: [
                'title' => 'Business days are shared',
                'message' => 'This delay uses the business-wide weekdays and dates that do not count. You can change them later under Settings & setup → Business days.',
                'action_label' => 'Open Business days',
                'action_url' => route('crm.business-calendar.edit'),
            ],
        );
    }
}
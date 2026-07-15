<?php

namespace App\Modules\FlowRoutes\Requests;

use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use Illuminate\Foundation\Http\FormRequest;

class StoreFlowRoutePointRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'capability_id' => $this->capabilityIdRules(),
            'name' => ['nullable', 'string', 'max:255'],
        ];

        $pointType = $this->authoringPointType();
        $registry = app(AutomationPointAuthoringRegistry::class);

        if ($pointType !== null && $registry->has($pointType)) {
            $rules = array_replace(
                $rules,
                $registry->rules($pointType, $this->authoringContext()),
            );
        }

        return $rules;
    }

    /** @return array<int, mixed> */
    protected function capabilityIdRules(): array
    {
        return ['required', 'integer', 'exists:flow_route_capabilities,id'];
    }

    protected function authoringPointType(): ?string
    {
        return $this->resolvedCapability()?->point_type;
    }

    protected function resolvedCapability(): ?FlowRouteCapability
    {
        $capabilityId = $this->input('capability_id');

        if (! is_numeric($capabilityId)) {
            return null;
        }

        return FlowRouteCapability::query()->active()->find((int) $capabilityId);
    }

    protected function authoringContext(): AutomationPointAuthoringContext
    {
        $route = $this->route('flowRoute');
        $point = $this->route('flowRoutePoint');
        $capability = $point instanceof FlowRoutePoint
            ? $point->capability
            : $this->resolvedCapability();

        return new AutomationPointAuthoringContext(
            existingPointTypes: $route instanceof FlowRoute
                ? $route->activeFlowRoutePoints()
                    ->orderBy('sort_order')
                    ->pluck('type')
                    ->map(fn (mixed $type): string => (string) $type)
                    ->all()
                : [],
            container: $route instanceof FlowRoute ? $route : null,
            point: $point instanceof FlowRoutePoint ? $point : null,
            capability: $capability,
        );
    }
}

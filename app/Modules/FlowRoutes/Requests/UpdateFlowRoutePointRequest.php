<?php

namespace App\Modules\FlowRoutes\Requests;

use App\Modules\FlowRoutes\Models\FlowRoutePoint;

class UpdateFlowRoutePointRequest extends StoreFlowRoutePointRequest
{
    /** @return array<int, mixed> */
    protected function capabilityIdRules(): array
    {
        return ['sometimes', 'nullable', 'integer', 'exists:flow_route_capabilities,id'];
    }

    protected function authoringPointType(): ?string
    {
        $point = $this->route('flowRoutePoint');

        if ($point instanceof FlowRoutePoint) {
            return (string) $point->type;
        }

        return parent::authoringPointType();
    }
}

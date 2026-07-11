<?php

namespace App\Modules\FlowRoutes\Requests;

class UpdateFlowRoutePointRequest extends StoreFlowRoutePointRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'capability_id' => ['sometimes', 'nullable', 'integer', 'exists:flow_route_capabilities,id'],
        ]);
    }
}

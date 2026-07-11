<?php

namespace App\Modules\FlowRoutes\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFlowRoutePointOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'point_ids' => ['required', 'array', 'min:1'],
            'point_ids.*' => ['required', 'integer', 'distinct', 'exists:flow_route_points,id'],
        ];
    }
}

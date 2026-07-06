<?php

namespace App\Modules\FlowRoutes\Requests;

use App\Modules\FlowRoutes\Models\FlowRoute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFlowRouteTriggerBindingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $triggerType = (string) $this->input('trigger_type');
        $triggerKey = (string) $this->input('trigger_key');

        return [
            'trigger_type' => [
                'required',
                'string',
                Rule::in([
                    FlowRoute::TRIGGER_CONTACT_STATUS,
                    FlowRoute::TRIGGER_AUTOMATION_EVENT,
                ]),
            ],
            'trigger_key' => [
                'required',
                'string',
                'max:255',
            ],
            'flow_route_id' => [
                Rule::requiredIf($triggerType === FlowRoute::TRIGGER_CONTACT_STATUS),
                'nullable',
                'integer',
                Rule::exists('flow_routes', 'id')->where(function ($query) use ($triggerType, $triggerKey) {
                    $query
                        ->where('trigger_type', $triggerType)
                        ->where('trigger_key', $triggerKey)
                        ->where('is_active', true);
                }),
            ],
            'flow_route_ids' => [
                'nullable',
                'array',
            ],
            'flow_route_ids.*' => [
                'integer',
                Rule::exists('flow_routes', 'id')->where(function ($query) use ($triggerType, $triggerKey) {
                    $query
                        ->where('trigger_type', $triggerType)
                        ->where('trigger_key', $triggerKey)
                        ->where('is_active', true);
                }),
            ],
        ];
    }
}

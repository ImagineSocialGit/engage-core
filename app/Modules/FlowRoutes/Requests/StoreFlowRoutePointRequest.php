<?php

namespace App\Modules\FlowRoutes\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreFlowRoutePointRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'capability_id' => ['required', 'integer', 'exists:flow_route_capabilities,id'],
            'name' => ['nullable', 'string', 'max:255'],

            'wait_mode' => ['nullable', 'in:duration,resume_at'],
            'duration_value' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'duration_unit' => ['nullable', 'in:minutes,hours,days,weeks'],
            'resume_at' => ['nullable', 'date'],

            'contact_status_key' => ['nullable', 'string', 'max:255'],

            'task_template_key' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'due_offset_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'priority' => ['nullable', 'string', 'max:50'],

            'message_template_preset_id' => ['nullable', 'integer', 'exists:message_template_presets,id'],

            'campaign_key' => ['nullable', 'string', 'max:255'],
            'skip_pending_messages' => ['nullable', 'boolean'],
        ];
    }
}

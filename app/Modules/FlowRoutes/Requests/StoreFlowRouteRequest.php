<?php

namespace App\Modules\FlowRoutes\Requests;

use App\Modules\Core\Automation\CoreAutomationTriggerAuthoringContributor;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlowRouteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(AutomationTriggerAuthoringRegistry $triggers): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'trigger_authoring_key' => [
                'required',
                'string',
                Rule::in($triggers->availableKeys()),
            ],
        ];

        $key = trim((string) $this->input('trigger_authoring_key'));

        if ($key !== '' && in_array($key, $triggers->availableKeys(), true)) {
            $rules = array_replace($rules, $triggers->rules($key));
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->filled('trigger_authoring_key') && $this->filled('contact_status_id')) {
            $this->merge([
                'trigger_authoring_key' => CoreAutomationTriggerAuthoringContributor::CONTACT_STATUS,
            ]);
        }
    }

    public function routeName(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function routeDescription(): ?string
    {
        $description = trim((string) ($this->validated('description') ?? ''));

        return $description !== '' ? $description : null;
    }

    public function contactStatusId(): int
    {
        return (int) $this->validated('contact_status_id');
    }

    public function triggerAuthoringKey(): string
    {
        return trim((string) $this->validated('trigger_authoring_key'));
    }
}
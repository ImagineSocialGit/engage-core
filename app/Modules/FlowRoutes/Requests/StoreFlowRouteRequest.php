<?php

namespace App\Modules\FlowRoutes\Requests;

use App\Modules\Core\Automation\CoreAutomationTriggerAuthoringContributor;
use App\Modules\FlowRoutes\Models\FlowRoute;
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
            'authoring_kind' => [
                'nullable',
                'string',
                Rule::in(FlowRoute::AUTHORING_KINDS),
            ],
            'trigger_authoring_key' => [
                'required',
                'string',
                Rule::in($triggers->registeredKeys()),
            ],
            'starter_capability_key' => ['nullable', 'string', 'max:255'],
        ];

        $key = trim((string) $this->input('trigger_authoring_key'));

        if ($key !== '' && in_array($key, $triggers->registeredKeys(), true)) {
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

    public function starterCapabilityKey(): ?string
    {
        $key = trim((string) ($this->validated('starter_capability_key') ?? ''));

        return $key !== '' ? $key : null;
    }

    public function authoringKind(): string
    {
        $kind = trim((string) ($this->validated('authoring_kind') ?? ''));

        return in_array($kind, FlowRoute::AUTHORING_KINDS, true)
            ? $kind
            : FlowRoute::AUTHORING_KIND_ROUTE;
    }
}
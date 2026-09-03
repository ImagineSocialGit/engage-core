<?php

namespace App\Modules\Forms\Automation;

use App\Modules\Forms\Models\FormDefinition;
use App\Support\AutomationTriggers\Contracts\AutomationTriggerAuthoringContributor;
use App\Support\AutomationTriggers\Data\AutomationTriggerAuthoringDefinition;
use App\Support\AutomationTriggers\Data\AutomationTriggerSelection;
use Illuminate\Validation\Rule;

final class FormSubmissionAutomationTriggerAuthoringContributor implements AutomationTriggerAuthoringContributor
{
    public const KEY = 'forms.form_submitted';
    public const EVENT_KEY = 'form.submitted';
    public const FORM_KEY_EVENT_PATH = 'automation_event.payload.form.key';

    public function definitions(): iterable
    {
        yield new AutomationTriggerAuthoringDefinition(
            key: self::KEY,
            moduleKey: 'forms',
            name: 'Form is submitted',
            description: 'Run when a contact submits one selected published form.',
            sortOrder: 60,
        );
    }

    public function available(string $authoringKey): bool
    {
        return $authoringKey === self::KEY
            && FormDefinition::query()
                ->where('status', FormDefinition::STATUS_ACTIVE)
                ->whereNotNull('current_form_version_id')
                ->exists();
    }

    public function fields(string $authoringKey): array
    {
        return [[
            'type' => 'select',
            'name' => 'form_key',
            'label' => 'Form',
            'required' => true,
            'placeholder' => 'Choose a form',
            'options' => $this->forms(),
        ]];
    }

    public function rules(string $authoringKey): array
    {
        return [
            'form_key' => ['required', 'string', Rule::in(array_column($this->forms(), 'value'))],
        ];
    }

    public function selection(string $authoringKey, array $input): AutomationTriggerSelection
    {
        return new AutomationTriggerSelection(
            triggerType: 'automation_event',
            triggerKey: self::EVENT_KEY,
            entryConditions: [[
                'source' => 'execution_meta',
                'path' => self::FORM_KEY_EVENT_PATH,
                'operator' => 'equals',
                'value' => trim((string) $input['form_key']),
            ]],
        );
    }

    /** @return array<int, array{value: string, label: string}> */
    private function forms(): array
    {
        return FormDefinition::query()
            ->where('status', FormDefinition::STATUS_ACTIVE)
            ->whereNotNull('current_form_version_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['key', 'name'])
            ->map(fn (FormDefinition $form): array => [
                'value' => (string) $form->key,
                'label' => (string) $form->name,
            ])
            ->all();
    }
}
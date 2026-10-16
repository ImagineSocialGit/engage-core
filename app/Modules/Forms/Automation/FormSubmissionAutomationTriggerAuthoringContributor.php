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

    public const CONTEXT_KEY_EVENT_PATH = 'automation_event.payload.context.key';

    public const CONTEXT_REFERENCE_EVENT_PATH = 'automation_event.payload.context.reference';

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
        return [
            [
                'type' => 'select',
                'name' => 'form_key',
                'label' => 'Form',
                'required' => true,
                'placeholder' => 'Choose a form',
                'options' => $this->forms(),
            ],
            [
                'type' => 'hidden',
                'name' => 'context_key',
                'required' => false,
            ],
            [
                'type' => 'hidden',
                'name' => 'context_reference',
                'required' => false,
            ],
        ];
    }

    public function rules(string $authoringKey): array
    {
        return [
            'form_key' => [
                'required',
                'string',
                Rule::in(array_column($this->forms(), 'value')),
            ],
            'context_key' => [
                'nullable',
                'string',
                'max:80',
                'regex:/^[a-z][a-z0-9_.-]{0,79}$/',
                'required_with:context_reference',
            ],
            'context_reference' => [
                'nullable',
                'string',
                'max:255',
                'required_with:context_key',
            ],
        ];
    }

    public function selection(
        string $authoringKey,
        array $input,
    ): AutomationTriggerSelection {
        $conditions = [[
            'source' => 'execution_meta',
            'path' => self::FORM_KEY_EVENT_PATH,
            'operator' => 'equals',
            'value' => trim((string) $input['form_key']),
        ]];

        $contextKey = trim((string) ($input['context_key'] ?? ''));
        $contextReference = trim((string) ($input['context_reference'] ?? ''));

        if ($contextKey !== '' && $contextReference !== '') {
            $conditions[] = [
                'source' => 'execution_meta',
                'path' => self::CONTEXT_KEY_EVENT_PATH,
                'operator' => 'equals',
                'value' => $contextKey,
            ];
            $conditions[] = [
                'source' => 'execution_meta',
                'path' => self::CONTEXT_REFERENCE_EVENT_PATH,
                'operator' => 'equals',
                'value' => $contextReference,
            ];
        }

        return new AutomationTriggerSelection(
            triggerType: 'automation_event',
            triggerKey: self::EVENT_KEY,
            entryConditions: $conditions,
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
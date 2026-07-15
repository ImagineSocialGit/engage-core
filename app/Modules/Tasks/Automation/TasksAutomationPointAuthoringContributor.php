<?php

namespace App\Modules\Tasks\Automation;

use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TasksAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: 'create_task',
            moduleKey: 'tasks',
            name: 'Create a Task automatically',
            description: 'Create a new Task from a reusable Task Template every time a record reaches this Point.',
            tip: 'Automatic Task creation requires a Task Template so the same work, responsibility, assignment, timing, and linked-record defaults stay consistent.',
            useCases: [
                'Create an initial contact task.',
                'Create a follow-up task after a waiting period.',
            ],
            typeLabel: 'Task',
            genericLabels: ['create task', 'create a task automatically'],
            generatedPrefixes: ['create task:', 'create task from '],
        );
    }

    public function available(string $pointType, AutomationPointAuthoringContext $context): bool
    {
        return TaskTemplate::query()->active()->exists();
    }

    public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array
    {
        return [
            [
                'type' => 'notice',
                'title' => 'This creates a Task automatically',
                'body' => 'A new Task will be created from the selected template every time a record reaches this Point. This does not create a one-time Task now.',
                'tone' => 'warning',
            ],
            [
                'type' => 'select',
                'name' => 'task_template_key',
                'label' => 'Task Template',
                'required' => true,
                'value' => (string) ($definition['task_template_key'] ?? ''),
                'placeholder' => 'Choose a Task Template',
                'help' => 'The template owns the reusable Task defaults used whenever this automation runs.',
                'options' => TaskTemplate::query()
                    ->active()
                    ->orderBy('name')
                    ->get(['key', 'name', 'title', 'description'])
                    ->map(fn (TaskTemplate $template): array => [
                        'value' => (string) $template->key,
                        'label' => (string) ($template->name ?: $template->title),
                        'description' => (string) ($template->description ?: $template->title),
                    ])->all(),
            ],
        ];
    }

    public function rules(string $pointType, AutomationPointAuthoringContext $context): array
    {
        return [
            'task_template_key' => ['required', 'string', 'max:255'],
        ];
    }

    public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array
    {
        $templateKey = trim((string) ($input['task_template_key'] ?? ''));
        $template = TaskTemplate::query()->active()->where('key', $templateKey)->first();

        if (! $template instanceof TaskTemplate) {
            throw ValidationException::withMessages([
                'task_template_key' => 'Choose an active Task Template.',
            ]);
        }

        return ['task_template_key' => (string) $template->key];
    }

    public function pointName(
        string $pointType,
        string $fallback,
        array $input,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        $customName = trim((string) ($input['name'] ?? ''));

        if ($customName !== '') {
            return $customName;
        }

        return 'Create task from '.$this->templateLabel((string) ($definition['task_template_key'] ?? ''));
    }

    public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return 'Create task: '.$this->templateLabel((string) ($definition['task_template_key'] ?? '')).'.';
    }

    public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string
    {
        return 'Create task from '.$this->templateLabel((string) ($definition['task_template_key'] ?? ''));
    }

    private function templateLabel(string $key): string
    {
        $name = $key !== ''
            ? TaskTemplate::query()->where('key', $key)->value('name')
            : null;

        return is_string($name) && trim($name) !== ''
            ? $name
            : ($key !== '' ? Str::headline($key) : 'selected Task Template');
    }
}

<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Requests\UpdateTaskTemplateRequest;
use App\Modules\Tasks\Requests\SaveTaskTemplateRequest;
use App\Modules\Core\Access\Services\AssignmentDirectory;
use App\Modules\Tasks\Services\TaskTemplateAssignmentResolver;
use App\Modules\Tasks\Services\TaskTemplateTimingResolver;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Str;
use App\Modules\Tasks\Services\TaskTemplatePresentationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TaskTemplateController extends Controller
{
    public function __construct(
        private readonly TaskTemplatePresentationResolver $presentation,
        private readonly AssignmentDirectory $directory,
        private readonly TaskTemplateAssignmentResolver $assignments,
        private readonly TaskTemplateTimingResolver $timing,
    ) {}

    public function index(): View
    {
        $templates = TaskTemplate::query()
            ->with(['assignedTo', 'responsible'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (TaskTemplate $template): array => $this->presentation->present($template));

        return view('crm.tasks.templates.index', [
            'title' => 'Task Templates',
            'heading' => 'Task Templates',
            'templates' => $templates,
            'createUrl' => route('crm.tasks.templates.create'),
        ]);
    }

    public function create(): View
    {
        $template = new TaskTemplate([
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
            'is_active' => true,
        ]);

        return view('crm.tasks.templates.create', $this->formData($template) + [
            'title' => 'Create Task Template',
            'heading' => 'Create Task Template',
        ]);
    }

    public function store(SaveTaskTemplateRequest $request): RedirectResponse
    {
        $template = TaskTemplate::query()->create([
            'key' => 'manual.'.Str::uuid(),
            'source' => TaskTemplate::SOURCE_MANUAL,
            ...$this->attributes($request, null),
            'is_customized' => true,
            'customized_at' => now(),
        ]);

        return redirect()->route('crm.tasks.templates.edit', $template)
            ->with('success', 'Task Template created.');
    }

    public function edit(TaskTemplate $taskTemplate): View
    {
        return view('crm.tasks.templates.edit', $this->formData($taskTemplate) + [
            'title' => $taskTemplate->name,
            'heading' => 'Edit Task Template',
            'taskTemplate' => $taskTemplate,
        ]);
    }

    public function update(
        UpdateTaskTemplateRequest $request,
        TaskTemplate $taskTemplate,
    ): RedirectResponse {
        $taskTemplate->forceFill([
            ...$this->attributes($request, $taskTemplate),
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        return redirect()
            ->route('crm.tasks.templates.edit', $taskTemplate)
            ->with('success', 'Task Template updated.');
    }

    /** @return array<string, mixed> */
    private function formData(TaskTemplate $template): array
    {
        return [
            'taskTemplate' => $template,
            'users' => $this->directory->activeUsers(),
            'teams' => $this->directory->activeTeams(),
            'assignmentState' => $this->assignments->formState($template),
            'timingState' => $this->timing->formState($template),
            'responsiblePartyLabels' => [
                Task::RESPONSIBLE_PARTY_INTERNAL => 'Internal team',
                Task::RESPONSIBLE_PARTY_CONTACT => config('contacts.labels.singular', 'Contact'),
                Task::RESPONSIBLE_PARTY_THIRD_PARTY => 'Third party',
                Task::RESPONSIBLE_PARTY_UNKNOWN => 'Not specified',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(SaveTaskTemplateRequest $request, ?TaskTemplate $template): array
    {
        $validated = $request->validated();
        $base = collect($validated)->only([
            'name', 'title', 'description', 'task_description', 'priority', 'responsible_party', 'is_active',
        ])->all();

        $assignment = array_key_exists('assignment_mode', $validated)
            && $validated['assignment_mode'] !== null
            ? $this->assignments->attributes($validated)
            : [
                'assigned_to_type' => $template?->assigned_to_type,
                'assigned_to_id' => $template?->assigned_to_id,
                'assigned_to_strategy' => $template?->assigned_to_strategy ?? TaskTemplate::ASSIGNED_TO_STRATEGY_UNASSIGNED,
            ];

        return [
            ...$base,
            ...$assignment,
            ...$this->timing->attributes($validated, $template?->meta),
        ];
    }
}
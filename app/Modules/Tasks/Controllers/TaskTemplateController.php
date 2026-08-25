<?php

namespace App\Modules\Tasks\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Requests\UpdateTaskTemplateRequest;
use App\Modules\Tasks\Services\TaskTemplatePresentationResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TaskTemplateController extends Controller
{
    public function __construct(
        private readonly TaskTemplatePresentationResolver $presentation,
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
        ]);
    }

    public function edit(TaskTemplate $taskTemplate): View
    {
        return view('crm.tasks.templates.edit', [
            'title' => $taskTemplate->name,
            'heading' => 'Edit Task Template',
            'taskTemplate' => $taskTemplate,
            'presented' => $this->presentation->present($taskTemplate),
        ]);
    }

    public function update(
        UpdateTaskTemplateRequest $request,
        TaskTemplate $taskTemplate,
    ): RedirectResponse {
        $taskTemplate->forceFill([
            ...$request->validated(),
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        return redirect()
            ->route('crm.tasks.templates.edit', $taskTemplate)
            ->with('success', 'Task Template updated.');
    }
}
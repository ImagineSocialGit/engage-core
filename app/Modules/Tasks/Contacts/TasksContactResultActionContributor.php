<?php

namespace App\Modules\Tasks\Contacts;

use App\Modules\Core\Contracts\Contacts\ContactResultActionContributor;
use App\Modules\Core\Data\Contacts\ContactResultAction;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Services\TaskTemplatePresentationResolver;

final class TasksContactResultActionContributor implements ContactResultActionContributor
{
    public function __construct(
        private readonly TaskTemplatePresentationResolver $presentation,
    ) {}

    public function actions(): iterable
    {
        $plural = str((string) config('contacts.labels.plural', 'contacts'))->lower()->toString();
        $templates = TaskTemplate::query()
            ->active()
            ->with(['assignedTo', 'responsible'])
            ->orderBy('name')
            ->get()
            ->map(fn (TaskTemplate $template): array => $this->presentation->present($template))
            ->values();

        yield new ContactResultAction(
            key: 'tasks.create_for_result',
            label: 'Add Tasks',
            description: 'Create one linked Task for every matching '.$plural.' using a Task Template.',
            view: 'crm.tasks.contact-result-actions.create',
            capability: 'contacts.manage',
            sort: 10,
            data: ['templates' => $templates],
            groupKey: 'tasks',
            groupLabel: 'Tasks',
            groupDescription: 'Create consistent follow-up work for this result set.',
            groupSort: 20,
        );
    }
}
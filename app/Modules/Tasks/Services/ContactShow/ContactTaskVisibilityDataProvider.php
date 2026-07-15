<?php

namespace App\Modules\Tasks\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ContactTaskVisibilityDataProvider implements ContactShowDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $contactTypes = array_values(array_unique([
            Contact::class,
            $contact->getMorphClass(),
        ]));

        $tasks = Task::query()
            ->with([
                'assignedTo',
                'responsible',
                'links.linkable',
            ])
            ->whereHas('links', function (Builder $query) use ($contact, $contactTypes): void {
                $query
                    ->whereIn('linkable_type', $contactTypes)
                    ->where('linkable_id', $contact->getKey());
            })
            ->whereNull('archived_at')
            ->orderByRaw(
                'CASE WHEN status = ? THEN 0 WHEN status = ? THEN 1 ELSE 2 END',
                [Task::STATUS_OPEN, Task::STATUS_COMPLETED],
            )
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->latest('id')
            ->limit(8)
            ->get();

        return [
            'contactVisibilitySections' => [
                'tasks' => [
                    'title' => 'Tasks',
                    'module' => 'tasks',
                    'description' => 'Open and recent manual actions or dependencies linked to this '.config('contacts.labels.singular').'.',
                    'empty' => 'No linked tasks found.',
                    'items' => $tasks->map(fn (Task $task): array => [
                        'title' => $task->title,
                        'subtitle' => $task->description,
                        'status' => $this->label($task->status),
                        'meta' => [
                            'Due' => $this->date($task->due_at),
                            'Priority' => $this->label($task->priority),
                            'Assigned To' => $this->modelLabel($task->assignedTo),
                            'Responsible Party' => $this->label($task->responsible_party),
                            'Responsible' => $this->modelLabel($task->responsible),
                            'Source' => $this->label($task->source),
                            'Completed' => $this->date($task->completed_at),
                        ],
                    ])->all(),
                ],
            ],
        ];
    }

    private function label(?string $value): ?string
    {
        return filled($value)
            ? Str::of($value)->replace('_', ' ')->title()->toString()
            : null;
    }

    private function modelLabel(mixed $model): ?string
    {
        if (! $model) {
            return null;
        }

        foreach (['name', 'email', 'title'] as $attribute) {
            $value = $model->{$attribute} ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return Str::afterLast($model::class, '\\').' #'.$model->getKey();
    }

    private function date(mixed $date): ?string
    {
        return $date?->timezone(
            config('client.timezone', config('app.timezone', 'UTC'))
        )->format('M j, Y g:i A');
    }
}

<?php

namespace App\Modules\Tasks\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactShowDataProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use Illuminate\Support\Str;

class ContactTaskVisibilityDataProvider implements ContactShowDataProvider
{
    /**
     * @return array<string, mixed>
     */
    public function dataFor(Contact $contact): array
    {
        $contactType = $contact->getMorphClass();

        $tasks = Task::query()
            ->with(['assignedTo', 'responsible'])
            ->where(function ($query) use ($contact, $contactType) {
                $query
                    ->where(function ($query) use ($contact, $contactType) {
                        $query->where('related_type', $contactType)
                            ->where('related_id', $contact->id);
                    })
                    ->orWhere(function ($query) use ($contact, $contactType) {
                        $query->where('responsible_type', $contactType)
                            ->where('responsible_id', $contact->id);
                    });
            })
            ->whereNull('archived_at')
            ->orderByRaw("FIELD(status, 'open', 'completed', 'canceled')")
            ->latest('due_at')
            ->latest()
            ->limit(8)
            ->get();

        return [
            'contactVisibilitySections' => [
                'tasks' => [
                    'title' => 'Tasks',
                    'description' => 'Open and recent manual actions/dependencies.',
                    'empty' => 'No active tasks found.',
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
        return $date?->timezone(config('app.timezone'))->format('M j, Y g:i A');
    }
}
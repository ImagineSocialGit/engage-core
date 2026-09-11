<?php

namespace App\Modules\Tasks\Services\Contacts\Filters;

use App\Modules\Core\Contracts\Contacts\ContactFilterCriterion;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;

final class TaskStateContactFilterCriterion implements ContactFilterCriterion
{
    private const OPEN = 'open';
    private const OVERDUE = 'overdue';

    public function key(): string
    {
        return 'task_state';
    }

    public function sortOrder(): int
    {
        return 60;
    }

    public function label(): string
    {
        return 'Tasks';
    }

    public function help(): ?string
    {
        return 'Find contacts with manual follow-up that is still open or already overdue.';
    }

    public function options(): array
    {
        return [
            ['value' => self::OPEN, 'label' => 'Has open task'],
            ['value' => self::OVERDUE, 'label' => 'Has overdue task'],
        ];
    }

    public function normalize(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $allowed = [self::OPEN, self::OVERDUE];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && in_array(trim($value), $allowed, true)
                ? trim($value)
                : null,
            $values,
        ))));
    }

    public function apply(Builder $query, array $values): void
    {
        $contact = new Contact();
        $contactTypes = array_values(array_unique([
            Contact::class,
            $contact->getMorphClass(),
        ]));
        $includeAnyOpen = in_array(self::OPEN, $values, true);
        $includeOverdue = in_array(self::OVERDUE, $values, true);

        $query->whereExists(function ($subquery) use ($contactTypes, $includeAnyOpen, $includeOverdue): void {
            $subquery
                ->selectRaw('1')
                ->from('task_links')
                ->join('tasks', 'tasks.id', '=', 'task_links.task_id')
                ->whereColumn('task_links.linkable_id', 'contacts.id')
                ->whereIn('task_links.linkable_type', $contactTypes)
                ->where('tasks.status', Task::STATUS_OPEN)
                ->whereNull('tasks.archived_at');

            if (! $includeAnyOpen && $includeOverdue) {
                $subquery
                    ->whereNotNull('tasks.due_at')
                    ->where('tasks.due_at', '<', now());
            }
        });
    }
}
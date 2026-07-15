<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Contracts\TaskLinkPresenterContract;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TaskLinkPresentationResolver
{
    /**
     * @param iterable<int, TaskLinkPresenterContract> $presenters
     */
    public function __construct(
        private readonly iterable $presenters,
    ) {}

    public function hasPresenter(Model $linkable): bool
    {
        foreach ($this->presenters as $presenter) {
            if ($presenter->supports($linkable)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, array{
     *     link_id: int,
     *     role: string,
     *     role_label: string,
     *     record: Model|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }>
     */
    public function forTask(Task $task): Collection
    {
        $task->loadMissing('links.linkable');

        $roleOrder = array_flip([
            TaskLink::ROLE_SUBJECT,
            TaskLink::ROLE_CONTEXT,
            TaskLink::ROLE_RESULT,
        ]);

        return $task->links
            ->sortBy(fn (TaskLink $link): string => sprintf(
                '%02d-%012d',
                $roleOrder[$link->role] ?? 99,
                $link->getKey(),
            ))
            ->map(fn (TaskLink $link): array => $this->present($link))
            ->values();
    }

    /**
     * @return array{
     *     link_id: int,
     *     role: string,
     *     role_label: string,
     *     record: Model|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }|null
     */
    public function primary(Task $task): ?array
    {
        return $this->forTask($task)->first();
    }

    /**
     * @return array{
     *     link_id: int,
     *     role: string,
     *     role_label: string,
     *     record: Model|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }
     */
    public function present(TaskLink $link): array
    {
        $link->loadMissing('linkable');

        $linkable = $link->linkable;

        if (! $linkable instanceof Model) {
            return $this->fallback($link, null);
        }

        foreach ($this->presenters as $presenter) {
            if (! $presenter->supports($linkable)) {
                continue;
            }

            return [
                'link_id' => (int) $link->getKey(),
                'role' => $link->role,
                'role_label' => Str::headline($link->role),
                ...$presenter->present($linkable),
            ];
        }

        return $this->fallback($link, $linkable);
    }

    /**
     * @return array{
     *     link_id: int,
     *     role: string,
     *     role_label: string,
     *     record: Model|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }
     */
    private function fallback(TaskLink $link, ?Model $linkable): array
    {
        if (! $linkable) {
            return [
                'link_id' => (int) $link->getKey(),
                'role' => $link->role,
                'role_label' => Str::headline($link->role),
                'record' => null,
                'type' => $link->linkable_type,
                'label' => 'Linked Record',
                'name' => 'Record unavailable',
                'url' => null,
                'details' => [],
            ];
        }

        return [
            'link_id' => (int) $link->getKey(),
            'role' => $link->role,
            'role_label' => Str::headline($link->role),
            'record' => $linkable,
            'type' => $linkable->getMorphClass(),
            'label' => Str::headline(class_basename($linkable)),
            'name' => $this->modelName($linkable),
            'url' => null,
            'details' => [],
        ];
    }

    private function modelName(Model $model): string
    {
        foreach (['name', 'title', 'email'] as $attribute) {
            $value = $model->{$attribute} ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return Str::headline(class_basename($model)).' #'.$model->getKey();
    }
}

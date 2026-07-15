<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Contracts\TaskAssigneeOptionProviderContract;
use App\Modules\Tasks\Data\TaskAssigneeOption;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class TaskAssigneeOptionsResolver
{
    /**
     * @param iterable<int, TaskAssigneeOptionProviderContract> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /**
     * @return Collection<int, TaskAssigneeOption>
     */
    public function options(?Model $actor = null): Collection
    {
        $options = collect();

        foreach ($this->providers as $provider) {
            $options = $options->concat($provider->options($actor));
        }

        return $options
            ->filter(fn (mixed $option): bool => $option instanceof TaskAssigneeOption)
            ->unique(fn (TaskAssigneeOption $option): string => $option->key())
            ->sortBy(fn (TaskAssigneeOption $option): string => mb_strtolower($option->label))
            ->values();
    }

    public function isAvailable(Model $assignee, ?Model $actor = null): bool
    {
        $type = $assignee->getMorphClass();
        $id = (string) $assignee->getKey();

        return $this->options($actor)->contains(
            fn (TaskAssigneeOption $option): bool =>
                $option->assignee->getMorphClass() === $type
                && (string) $option->assignee->getKey() === $id,
        );
    }
}

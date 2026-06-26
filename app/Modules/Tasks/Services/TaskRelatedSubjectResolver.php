<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Contracts\TaskRelatedSubjectResolverContract;
use App\Modules\Tasks\Models\Task;

class TaskRelatedSubjectResolver
{
    /**
     * @param iterable<int, TaskRelatedSubjectResolverContract> $resolvers
     */
    public function __construct(
        private readonly iterable $resolvers,
    ) {}

    /**
     * @return array{
     *     subject: object|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }
     */
    public function resolve(Task $task): array
    {
        $task->loadMissing('related');

        $related = $task->related;

        if (! $related) {
            return $this->fallback();
        }

        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($related)) {
                return $resolver->resolve($related);
            }
        }

        return $this->fallback();
    }

    /**
     * @return array{
     *     subject: object|null,
     *     type: ?string,
     *     label: string,
     *     name: string,
     *     url: ?string,
     *     details: array<string, string>
     * }
     */
    private function fallback(): array
    {
        return [
            'subject' => null,
            'type' => null,
            'label' => 'Related Record',
            'name' => '—',
            'url' => null,
            'details' => [],
        ];
    }
}
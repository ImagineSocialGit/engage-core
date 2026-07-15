<?php

namespace App\Modules\Tasks\Services;

use App\Modules\Tasks\Contracts\TaskRelatedSubjectResolverContract;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use Illuminate\Database\Eloquent\Model;

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
        $task->loadMissing('links.linkable');

        $linkable = $this->primaryLinkable($task);

        if (! $linkable) {
            return $this->fallback();
        }

        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($linkable)) {
                return $resolver->resolve($linkable);
            }
        }

        return $this->fallback();
    }

    private function primaryLinkable(Task $task): ?Model
    {
        foreach ([
            TaskLink::ROLE_SUBJECT,
            TaskLink::ROLE_CONTEXT,
            TaskLink::ROLE_RESULT,
        ] as $role) {
            $linkable = $task->links
                ->firstWhere('role', $role)
                ?->linkable;

            if ($linkable instanceof Model) {
                return $linkable;
            }
        }

        return null;
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

<?php

namespace App\Modules\Tasks\Jobs;

use App\Models\User;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Actions\CreateTaskFromTemplateAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CreateTaskForContactResultChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<int, int> $contactIds */
    public function __construct(
        public readonly array $contactIds,
        public readonly int $taskTemplateId,
        public readonly string $taskTemplateKey,
        public readonly int $actorUserId,
        public readonly string $operationId,
    ) {}

    public function handle(
        UserAccessService $access,
        ContactVisibility $visibility,
        CreateTaskFromTemplateAction $createTask,
    ): void {
        $actor = User::query()->find($this->actorUserId);

        if (! $actor instanceof User || ! $access->allows($actor, 'contacts.manage')) {
            return;
        }

        $template = TaskTemplate::query()
            ->active()
            ->whereKey($this->taskTemplateId)
            ->where('key', $this->taskTemplateKey)
            ->first();

        if (! $template instanceof TaskTemplate) {
            return;
        }

        $contacts = $visibility->apply(
            Contact::query()->whereIn('contacts.id', $this->contactIds),
            $actor,
        )->reorder()->orderBy('contacts.id')->get();

        foreach ($contacts as $contact) {
            $alreadyCreated = Task::query()
                ->where('meta->contact_result_action->operation_id', $this->operationId)
                ->where('meta->contact_result_action->contact_id', $contact->getKey())
                ->exists();

            if ($alreadyCreated) {
                continue;
            }

            $createTask->handle($template, [
                'source' => Task::SOURCE_MANUAL,
                'links' => [[
                    'linkable' => $contact,
                    'role' => TaskLink::ROLE_SUBJECT,
                ]],
                'link_context' => [
                    TaskTemplate::LINK_SOURCE_CURRENT_CONTACT => $contact,
                    TaskTemplate::LINK_SOURCE_CURRENT_SUBJECT => $contact,
                ],
                'meta' => [
                    'contact_result_action' => [
                        'operation_id' => $this->operationId,
                        'contact_id' => (int) $contact->getKey(),
                        'actor_user_id' => (int) $actor->getKey(),
                    ],
                ],
            ]);
        }
    }
}
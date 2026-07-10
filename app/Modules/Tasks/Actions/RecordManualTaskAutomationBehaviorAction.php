<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use App\Support\AutomationOpportunities\Actions\AutomationBehaviorAction;
use App\Support\AutomationOpportunities\Data\AutomationBehaviorData;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use Illuminate\Database\Eloquent\Model;

class RecordManualTaskAutomationBehaviorAction extends AutomationBehaviorAction
{
    public const ACTION_KEY = 'task.created_manually';

    public const CAPABILITY_KEY = 'tasks.create_task';

    public function handle(
        Task $task,
        ?Model $actor = null,
    ): ?AutomationBehaviorOccurrence {
        if ($task->source !== Task::SOURCE_MANUAL) {
            return null;
        }

        $contact = $this->relatedContact($task);

        if (! $contact) {
            return null;
        }

        $contactStatus = $this->contactStatus($contact);
        $taskTemplateKey = $this->taskTemplateKey($task);
        $normalizedTitle = $taskTemplateKey === null
            ? $this->normalizeTitle($task->title)
            : null;

        $fingerprintParts = [
            'related_subject_type' => 'contact',
            'contact_status_key' => $contactStatus?->key,
            'task_template_key' => $taskTemplateKey,
            'normalized_title' => $normalizedTitle,
        ];

        return $this->record(
            AutomationBehaviorData::make(
                actionKey: self::ACTION_KEY,
                actor: $actor,
                subject: $contact,
                capabilityKey: self::CAPABILITY_KEY,
                fingerprintParts: $fingerprintParts,
                context: [
                    'contact_status_key' => $contactStatus?->key,
                    'contact_status_name' => $contactStatus?->name,
                    'task_id' => $task->getKey(),
                    'task_title' => $task->title,
                    'task_template_key' => $taskTemplateKey,
                ],
                meta: [
                    'source' => 'task_controller.store',
                ],
            ),
        );
    }

    private function relatedContact(Task $task): ?Contact
    {
        if (! $task->related_type || ! $task->related_id) {
            return null;
        }

        $contactMorphClass = (new Contact())->getMorphClass();

        if (! in_array($task->related_type, [Contact::class, $contactMorphClass], true)) {
            return null;
        }

        return Contact::query()->find($task->related_id);
    }

    private function contactStatus(Contact $contact): ?object
    {
        $profile = $contact->getRelationValue('workflowProfile');

        return $profile?->contactStatus;
    }

    private function taskTemplateKey(Task $task): ?string
    {
        $value = $task->task_template_key;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function normalizeTitle(string $title): string
    {
        return str($title)
            ->lower()
            ->squish()
            ->toString();
    }
}

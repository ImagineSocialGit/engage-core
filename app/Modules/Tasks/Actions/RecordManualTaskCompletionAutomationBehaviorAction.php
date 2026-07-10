<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Models\Task;
use App\Support\AutomationOpportunities\Actions\AutomationBehaviorAction;
use App\Support\AutomationOpportunities\Data\AutomationBehaviorData;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class RecordManualTaskCompletionAutomationBehaviorAction extends AutomationBehaviorAction
{
    public const ACTION_KEY = 'task.completed_manually';

    public function handle(TaskCompleted $event): ?AutomationBehaviorOccurrence
    {
        if (! $this->isManualCrmCompletion($event)) {
            return null;
        }

        $contact = $this->relatedContact($event->task);

        if (! $contact) {
            return null;
        }

        $actor = $this->actor($event);

        if (! $actor) {
            return null;
        }

        $taskTemplateKey = $this->taskTemplateKey($event->task);
        $normalizedTitle = $taskTemplateKey === null
            ? $this->normalizeTitle($event->task->title)
            : null;

        return $this->recordEvidence(
            AutomationBehaviorData::make(
                actionKey: self::ACTION_KEY,
                actor: $actor,
                subject: $contact,
                fingerprintParts: [
                    'task_template_key' => $taskTemplateKey,
                    'normalized_title' => $normalizedTitle,
                ],
                context: [
                    'task_id' => $event->task->getKey(),
                    'task_title' => $event->task->title,
                    'task_template_key' => $taskTemplateKey,
                    'task_completed_at' => $event->occurredAt->toISOString(),
                ],
                meta: [
                    'source' => $event->source,
                    'surface' => data_get($event->meta, 'source'),
                    'pattern_role' => 'manual_task_completion_evidence',
                ],
                occurredAt: $event->occurredAt,
            ),
        );
    }

    private function isManualCrmCompletion(TaskCompleted $event): bool
    {
        return $event->source === 'crm'
            && data_get($event->meta, 'source') === 'task_controller.complete'
            && is_string($event->actorType)
            && trim($event->actorType) !== ''
            && $event->actorId !== null;
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

    private function actor(TaskCompleted $event): ?Model
    {
        if (! $event->actorType || $event->actorId === null) {
            return null;
        }

        $modelClass = Relation::getMorphedModel($event->actorType)
            ?? $event->actorType;

        if (! class_exists($modelClass)
            || ! is_subclass_of($modelClass, Model::class)
        ) {
            return null;
        }

        return $modelClass::query()->find($event->actorId);
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

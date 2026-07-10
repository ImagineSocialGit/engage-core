<?php

namespace App\Modules\Tasks\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Tasks\Models\Task;
use App\Support\AutomationOpportunities\Actions\AutomationBehaviorAction;
use App\Support\AutomationOpportunities\Actions\RecordAutomationEventCorrelationEvidenceAction;
use App\Support\AutomationOpportunities\Data\AutomationBehaviorData;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

class RecordManualTaskAutomationBehaviorAction extends AutomationBehaviorAction
{
    public const ACTION_KEY = 'task.created_manually';

    public const COMPOUND_ACTION_KEY = 'task.created_after_manual_status_change';

    public const AUTOMATION_EVENT_COMPOUND_ACTION_KEY = 'task.created_after_automation_event';

    public const CAPABILITY_KEY = 'tasks.create_task';

    public const RELATED_ACTION_WINDOW_MINUTES = 10;

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

        $occurrence = $this->record(
            AutomationBehaviorData::make(
                actionKey: self::ACTION_KEY,
                actor: $actor,
                subject: $contact,
                capabilityKey: self::CAPABILITY_KEY,
                fingerprintParts: [
                    'related_subject_type' => 'contact',
                    'contact_status_key' => $contactStatus?->key,
                    'task_template_key' => $taskTemplateKey,
                    'normalized_title' => $normalizedTitle,
                ],
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

        $this->recordRecentManualStatusChangePattern(
            task: $task,
            contact: $contact,
            actor: $actor,
            taskTemplateKey: $taskTemplateKey,
            normalizedTitle: $normalizedTitle,
        );

        $this->recordRecentAutomationEventPattern(
            task: $task,
            contact: $contact,
            actor: $actor,
            taskTemplateKey: $taskTemplateKey,
            normalizedTitle: $normalizedTitle,
        );

        return $occurrence;
    }

    private function recordRecentManualStatusChangePattern(
        Task $task,
        Contact $contact,
        ?Model $actor,
        ?string $taskTemplateKey,
        ?string $normalizedTitle,
    ): void {
        $transition = $this->recentManualStatusTransition(
            contact: $contact,
            task: $task,
            actor: $actor,
        );

        if ($transition === null) {
            return;
        }

        $fromStatus = $transition['from_status'];
        $toStatus = $transition['to_status'];
        $changedAt = $transition['changed_at'];

        $this->record(
            AutomationBehaviorData::make(
                actionKey: self::COMPOUND_ACTION_KEY,
                actor: $actor,
                subject: $contact,
                capabilityKey: self::CAPABILITY_KEY,
                fingerprintParts: [
                    'from_status_key' => $fromStatus?->key,
                    'to_status_key' => $toStatus->key,
                    'task_template_key' => $taskTemplateKey,
                    'normalized_title' => $normalizedTitle,
                ],
                context: [
                    'from_status_key' => $fromStatus?->key,
                    'from_status_name' => $fromStatus?->name,
                    'to_status_key' => $toStatus->key,
                    'to_status_name' => $toStatus->name,
                    'status_changed_at' => $changedAt->toISOString(),
                    'task_id' => $task->getKey(),
                    'task_title' => $task->title,
                    'task_template_key' => $taskTemplateKey,
                    'task_created_at' => $task->created_at?->toISOString(),
                ],
                meta: [
                    'source' => 'task_controller.store',
                    'pattern' => 'manual_status_change_then_manual_task_creation',
                    'window_minutes' => self::RELATED_ACTION_WINDOW_MINUTES,
                ],
            ),
        );
    }

    private function recordRecentAutomationEventPattern(
        Task $task,
        Contact $contact,
        ?Model $actor,
        ?string $taskTemplateKey,
        ?string $normalizedTitle,
    ): void {
        $trigger = $this->recentAutomationEventEvidence(
            contact: $contact,
            task: $task,
        );

        if (! $trigger instanceof AutomationBehaviorOccurrence) {
            return;
        }

        $eventKey = data_get($trigger->context, 'event_key');

        if (! is_string($eventKey) || trim($eventKey) === '') {
            return;
        }

        $this->record(
            AutomationBehaviorData::make(
                actionKey: self::AUTOMATION_EVENT_COMPOUND_ACTION_KEY,
                actor: $actor,
                subject: $contact,
                capabilityKey: self::CAPABILITY_KEY,
                fingerprintParts: [
                    'event_key' => $eventKey,
                    'task_template_key' => $taskTemplateKey,
                    'normalized_title' => $normalizedTitle,
                ],
                context: [
                    'event_key' => $eventKey,
                    'automation_event_occurred_at' => $trigger->occurred_at?->toISOString(),
                    'automation_event_subject_type' => data_get(
                        $trigger->context,
                        'automation_event_subject_type',
                    ),
                    'automation_event_subject_id' => data_get(
                        $trigger->context,
                        'automation_event_subject_id',
                    ),
                    'task_id' => $task->getKey(),
                    'task_title' => $task->title,
                    'task_template_key' => $taskTemplateKey,
                    'task_created_at' => $task->created_at?->toISOString(),
                ],
                meta: [
                    'source' => 'task_controller.store',
                    'pattern' => 'automation_event_then_manual_task_creation',
                    'window_minutes' => self::RELATED_ACTION_WINDOW_MINUTES,
                    'trigger_occurrence_id' => $trigger->getKey(),
                ],
            ),
        );
    }

    /**
     * @return array{
     *     from_status: ContactStatus|null,
     *     to_status: ContactStatus,
     *     changed_at: CarbonImmutable
     * }|null
     */
    private function recentManualStatusTransition(
        Contact $contact,
        Task $task,
        ?Model $actor,
    ): ?array {
        if (! $actor || ! $task->created_at) {
            return null;
        }

        $profile = $contact->getRelationValue('workflowProfile');

        if (! $profile) {
            return null;
        }

        $transition = data_get($profile->meta, 'last_status_change');

        if (! is_array($transition)) {
            return null;
        }

        if (($transition['reason'] ?? null) !== 'crm_manual_status_update'
            || ($transition['source'] ?? null) !== 'crm'
            || data_get($transition, 'meta.source') !== 'contact_show_status_form'
        ) {
            return null;
        }

        if (($transition['actor_type'] ?? null) !== $actor->getMorphClass()
            || (int) ($transition['actor_id'] ?? 0) !== (int) $actor->getKey()
        ) {
            return null;
        }

        $fromStatusId = $this->nullableInt($transition['from_contact_status_id'] ?? null);
        $toStatusId = $this->nullableInt($transition['to_contact_status_id'] ?? null);

        if ($toStatusId === null || $fromStatusId === $toStatusId) {
            return null;
        }

        $changedAtValue = $transition['changed_at'] ?? null;

        if (! is_string($changedAtValue) || trim($changedAtValue) === '') {
            return null;
        }

        $changedAt = CarbonImmutable::parse($changedAtValue);
        $taskCreatedAt = CarbonImmutable::instance($task->created_at);

        if ($changedAt->isAfter($taskCreatedAt)
            || $changedAt->isBefore(
                $taskCreatedAt->subMinutes(self::RELATED_ACTION_WINDOW_MINUTES),
            )
        ) {
            return null;
        }

        $toStatus = ContactStatus::query()->find($toStatusId);

        if (! $toStatus instanceof ContactStatus) {
            return null;
        }

        $fromStatus = $fromStatusId !== null
            ? ContactStatus::query()->find($fromStatusId)
            : null;

        return [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'changed_at' => $changedAt,
        ];
    }

    private function recentAutomationEventEvidence(
        Contact $contact,
        Task $task,
    ): ?AutomationBehaviorOccurrence {
        if (! $task->created_at) {
            return null;
        }

        $taskCreatedAt = CarbonImmutable::instance($task->created_at);

        return AutomationBehaviorOccurrence::query()
            ->forAction(RecordAutomationEventCorrelationEvidenceAction::ACTION_KEY)
            ->where('subject_type', $contact->getMorphClass())
            ->where('subject_id', $contact->getKey())
            ->whereBetween('occurred_at', [
                $taskCreatedAt->subMinutes(self::RELATED_ACTION_WINDOW_MINUTES),
                $taskCreatedAt,
            ])
            ->latest('occurred_at')
            ->latest('id')
            ->first();
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

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

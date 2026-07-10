<?php

namespace App\Modules\Workflow\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use App\Support\AutomationOpportunities\Actions\AutomationBehaviorAction;
use App\Support\AutomationOpportunities\Data\AutomationBehaviorData;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class RecordManualStatusTransitionAutomationBehaviorAction extends AutomationBehaviorAction
{
    public const ACTION_KEY = 'contact.status_changed_after_manual_task_completion';

    public const TASK_COMPLETION_EVIDENCE_ACTION_KEY = 'task.completed_manually';

    public const CAPABILITY_KEY = 'flow_routes.change_status';

    public const RELATED_ACTION_WINDOW_MINUTES = 10;

    public function handle(
        ContactWorkflowStatusTransition $transition,
    ): ?AutomationBehaviorOccurrence {
        if (! $this->isManualCrmStatusTransition($transition)) {
            return null;
        }

        if (function_exists('module_enabled') && ! module_enabled('flow_routes')) {
            return null;
        }

        $contact = Contact::query()->find($transition->contactId);

        if (! $contact) {
            return null;
        }

        $taskCompletion = $this->recentManualTaskCompletion(
            transition: $transition,
            contact: $contact,
        );

        if (! $taskCompletion) {
            return null;
        }

        $fromStatus = $transition->fromContactStatusId !== null
            ? ContactStatus::query()->find($transition->fromContactStatusId)
            : null;

        $toStatus = ContactStatus::query()->find($transition->toContactStatusId);

        if (! $toStatus) {
            return null;
        }

        $actor = $this->actor(
            actorType: $transition->actorType,
            actorId: $transition->actorId,
        );

        if (! $actor) {
            return null;
        }

        return $this->record(
            AutomationBehaviorData::make(
                actionKey: self::ACTION_KEY,
                actor: $actor,
                subject: $contact,
                capabilityKey: self::CAPABILITY_KEY,
                fingerprintParts: [
                    'task_template_key' => data_get(
                        $taskCompletion->fingerprint_parts,
                        'task_template_key',
                    ),
                    'normalized_task_title' => data_get(
                        $taskCompletion->fingerprint_parts,
                        'normalized_title',
                    ),
                    'from_status_key' => $fromStatus?->key,
                    'to_status_key' => $toStatus->key,
                ],
                context: [
                    'task_id' => data_get($taskCompletion->context, 'task_id'),
                    'task_title' => data_get($taskCompletion->context, 'task_title'),
                    'task_template_key' => data_get(
                        $taskCompletion->context,
                        'task_template_key',
                    ),
                    'task_completed_at' => $taskCompletion->occurred_at?->toISOString(),
                    'from_status_key' => $fromStatus?->key,
                    'from_status_name' => $fromStatus?->name,
                    'to_status_key' => $toStatus->key,
                    'to_status_name' => $toStatus->name,
                    'status_changed_at' => $transition->occurredAt->toISOString(),
                ],
                meta: [
                    'source' => 'workflow.contact_status_changed',
                    'pattern' => 'manual_task_completion_then_manual_status_change',
                    'window_minutes' => self::RELATED_ACTION_WINDOW_MINUTES,
                    'task_completion_occurrence_id' => $taskCompletion->getKey(),
                ],
                occurredAt: $transition->occurredAt,
            ),
        );
    }

    private function isManualCrmStatusTransition(
        ContactWorkflowStatusTransition $transition,
    ): bool {
        return $transition->changed()
            && $transition->reason === 'crm_manual_status_update'
            && $transition->source === 'crm'
            && data_get($transition->meta, 'source') === 'contact_show_status_form'
            && is_string($transition->actorType)
            && trim($transition->actorType) !== ''
            && $transition->actorId !== null;
    }

    private function recentManualTaskCompletion(
        ContactWorkflowStatusTransition $transition,
        Contact $contact,
    ): ?AutomationBehaviorOccurrence {
        $windowStart = $transition->occurredAt
            ->subMinutes(self::RELATED_ACTION_WINDOW_MINUTES);

        return AutomationBehaviorOccurrence::query()
            ->forAction(self::TASK_COMPLETION_EVIDENCE_ACTION_KEY)
            ->where('subject_type', $contact->getMorphClass())
            ->where('subject_id', $contact->getKey())
            ->where('actor_type', $transition->actorType)
            ->where('actor_id', $transition->actorId)
            ->whereBetween('occurred_at', [
                $windowStart,
                $transition->occurredAt,
            ])
            ->latest('occurred_at')
            ->first();
    }

    private function actor(
        ?string $actorType,
        ?int $actorId,
    ): ?Model {
        if (! $actorType || $actorId === null) {
            return null;
        }

        $modelClass = Relation::getMorphedModel($actorType)
            ?? $actorType;

        if (! class_exists($modelClass)
            || ! is_subclass_of($modelClass, Model::class)
        ) {
            return null;
        }

        return $modelClass::query()->find($actorId);
    }
}

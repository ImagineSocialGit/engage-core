<?php

namespace App\Support\AutomationOpportunities\Actions;

use App\Support\AutomationOpportunities\Data\AutomationBehaviorData;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use App\Support\AutomationOpportunities\Services\AutomationBehaviorFingerprintBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RecordAutomationBehaviorOccurrenceAction
{
    public function __construct(
        private readonly AutomationBehaviorFingerprintBuilder $fingerprintBuilder,
        private readonly EvaluateAutomationOpportunityAction $evaluateAutomationOpportunity,
    ) {}

    public function handle(
        AutomationBehaviorData $behavior,
        bool $evaluateOpportunity = true,
    ): AutomationBehaviorOccurrence {
        if (! $behavior->isValid()) {
            throw new InvalidArgumentException(
                'Automation behavior requires a non-empty action key and fingerprint parts.',
            );
        }

        return DB::transaction(function () use (
            $behavior,
            $evaluateOpportunity,
        ): AutomationBehaviorOccurrence {
            $occurrence = AutomationBehaviorOccurrence::query()->create([
                'action_key' => trim($behavior->actionKey),
                'actor_type' => $behavior->actor?->getMorphClass(),
                'actor_id' => $behavior->actor?->getKey(),
                'subject_type' => $behavior->subject?->getMorphClass(),
                'subject_id' => $behavior->subject?->getKey(),
                'capability_key' => $behavior->capabilityKey,
                'fingerprint' => $this->fingerprintBuilder->build(
                    $behavior->fingerprintParts,
                ),
                'fingerprint_parts' => $behavior->fingerprintParts,
                'context' => $behavior->context !== []
                    ? $behavior->context
                    : null,
                'meta' => $behavior->meta !== []
                    ? $behavior->meta
                    : null,
                'occurred_at' => $behavior->occurredAt,
            ]);

            if ($evaluateOpportunity) {
                $this->evaluateAutomationOpportunity->handle($occurrence);
            }

            return $occurrence->refresh();
        });
    }
}

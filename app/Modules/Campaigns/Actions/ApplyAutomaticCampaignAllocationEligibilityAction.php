<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Data\CampaignEligibilityEvaluationResult;
use App\Modules\Campaigns\Data\CampaignEligibilityLifecycleResult;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ApplyAutomaticCampaignAllocationEligibilityAction
{
    public function __construct(
        private readonly EvaluateCampaignEligibilityAction $evaluateEligibility,
        private readonly EnrollContactInCampaignAllocationAction $enroll,
        private readonly CancelCampaignAllocationEnrollmentAction $cancel,
    ) {}

    public function handle(
        Campaign $campaign,
        Contact $contact,
        Carbon|string|null $at = null,
    ): CampaignEligibilityLifecycleResult {
        if (! $campaign->isActive()) {
            return new CampaignEligibilityLifecycleResult(CampaignEligibilityLifecycleResult::SKIPPED_INACTIVE);
        }

        if (! $campaign->usesRecurringAllocation() || ! $campaign->usesAutomaticEnrollment()
            || ! $campaign->hasEligibilityCriteria()
            || (is_string($campaign->family_key) && trim($campaign->family_key) !== '')
            || ! in_array($campaign->ineligible_behavior, [Campaign::INELIGIBLE_CONTINUE, Campaign::INELIGIBLE_CANCEL], true)
        ) {
            return new CampaignEligibilityLifecycleResult(CampaignEligibilityLifecycleResult::SKIPPED_INVALID_CONFIGURATION);
        }

        return DB::transaction(function () use ($campaign, $contact, $at): CampaignEligibilityLifecycleResult {
            $evaluation = $this->evaluateEligibility->handle($campaign, $contact, $at);
            $active = CampaignAllocationEnrollment::query()
                ->where('campaign_id', $campaign->getKey())
                ->where('contact_id', $contact->getKey())
                ->where('status', CampaignAllocationEnrollment::STATUS_ACTIVE)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $evaluation->currentEligible) {
                if (! $active instanceof CampaignAllocationEnrollment) {
                    return new CampaignEligibilityLifecycleResult(
                        CampaignEligibilityLifecycleResult::NO_OPEN_ENROLLMENT,
                        evaluation: $evaluation,
                    );
                }

                if ($campaign->ineligible_behavior === Campaign::INELIGIBLE_CONTINUE) {
                    return new CampaignEligibilityLifecycleResult(
                        CampaignEligibilityLifecycleResult::CONTINUED,
                        evaluation: $evaluation,
                        allocationEnrollment: $active,
                    );
                }

                $cancelled = $this->cancel->cancelEnrollment(
                    enrollment: $active,
                    source: $evaluation->state,
                    reason: ApplyAutomaticCampaignEligibilityAction::INELIGIBLE_REASON,
                    skipPendingMessages: true,
                    meta: $this->lifecycleMeta($campaign, $contact, $evaluation),
                );

                return new CampaignEligibilityLifecycleResult(
                    CampaignEligibilityLifecycleResult::CANCELLED,
                    evaluation: $evaluation,
                    allocationEnrollment: $cancelled,
                );
            }

            if ($active instanceof CampaignAllocationEnrollment) {
                return new CampaignEligibilityLifecycleResult(
                    CampaignEligibilityLifecycleResult::EXISTING_OPEN_ENROLLMENT,
                    evaluation: $evaluation,
                    allocationEnrollment: $active,
                );
            }

            $hasHistory = CampaignAllocationEnrollment::query()
                ->where('campaign_id', $campaign->getKey())
                ->where('contact_id', $contact->getKey())
                ->exists();

            if ($hasHistory && (! $evaluation->becameEligible()
                || $evaluation->eligibilityCycle < 2
                || $campaign->reentry_policy !== Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN
            )) {
                return new CampaignEligibilityLifecycleResult(
                    CampaignEligibilityLifecycleResult::REENTRY_BLOCKED,
                    evaluation: $evaluation,
                    meta: [
                        'reentry_policy' => $campaign->reentry_policy,
                        'eligibility_cycle' => $evaluation->eligibilityCycle,
                    ],
                );
            }

            $enrollment = $this->enroll->handle(
                contact: $contact,
                campaignKey: (string) $campaign->key,
                source: $evaluation->state,
                meta: ['eligibility' => $this->lifecycleMeta($campaign, $contact, $evaluation)],
                entryKey: implode(':', [
                    'campaign_eligibility',
                    (int) $campaign->getKey(),
                    (int) $contact->getKey(),
                    'cycle',
                    $evaluation->eligibilityCycle,
                ]),
            );

            return new CampaignEligibilityLifecycleResult(
                CampaignEligibilityLifecycleResult::ENROLLED,
                evaluation: $evaluation,
                allocationEnrollment: $enrollment,
            );
        }, 3);
    }

    /** @return array<string, mixed> */
    private function lifecycleMeta(
        Campaign $campaign,
        Contact $contact,
        CampaignEligibilityEvaluationResult $evaluation,
    ): array {
        return [
            'source' => 'campaign_eligibility',
            'campaign_id' => (int) $campaign->getKey(),
            'campaign_key' => (string) $campaign->key,
            'contact_id' => (int) $contact->getKey(),
            'eligibility_state_id' => (int) $evaluation->state->getKey(),
            'eligibility_cycle' => $evaluation->eligibilityCycle,
            'transition' => $evaluation->transition,
            'evaluated_at' => $evaluation->state->last_evaluated_at?->toISOString(),
        ];
    }
}
<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Data\CampaignEligibilityEvaluationResult;
use App\Modules\Campaigns\Data\CampaignEligibilityLifecycleResult;
use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignEligibilityState;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ApplyAutomaticCampaignEligibilityAction
{
    public const INELIGIBLE_REASON = 'campaign_eligibility_ineligible';
    public const ELIGIBLE_AGAIN_REASON = 'campaign_eligibility_eligible_again';

    public function __construct(
        private readonly EvaluateCampaignEligibilityAction $evaluateEligibility,
        private readonly EnrollContactInCampaignAction $enrollContactInCampaign,
        private readonly PauseCampaignEnrollmentAction $pauseCampaignEnrollment,
        private readonly ResumeCampaignEnrollmentAction $resumeCampaignEnrollment,
        private readonly CancelCampaignEnrollmentAction $cancelCampaignEnrollment,
    ) {}

    public function handle(
        Campaign $campaign,
        Contact $contact,
        Carbon|string|null $at = null,
        bool $eagerProcess = true,
        Carbon|string|null $initialActionAt = null,
    ): CampaignEligibilityLifecycleResult {
        if (! $campaign->isActive()) {
            return new CampaignEligibilityLifecycleResult(
                action: CampaignEligibilityLifecycleResult::SKIPPED_INACTIVE,
            );
        }

        if (! $campaign->usesAutomaticEnrollment()) {
            return new CampaignEligibilityLifecycleResult(
                action: CampaignEligibilityLifecycleResult::SKIPPED_MANUAL,
            );
        }

        if (! $campaign->hasEligibilityCriteria()) {
            return new CampaignEligibilityLifecycleResult(
                action: CampaignEligibilityLifecycleResult::SKIPPED_INVALID_CONFIGURATION,
            );
        }

        return DB::transaction(function () use (
            $campaign,
            $contact,
            $at,
            $eagerProcess,
            $initialActionAt,
        ): CampaignEligibilityLifecycleResult {
            $evaluation = $this->evaluateEligibility->handle(
                campaign: $campaign,
                contact: $contact,
                at: $at,
            );

            return $evaluation->currentEligible
                ? $this->applyEligible(
                    campaign: $campaign,
                    contact: $contact,
                    evaluation: $evaluation,
                    eagerProcess: $eagerProcess,
                    initialActionAt: $initialActionAt,
                )
                : $this->applyIneligible(
                    campaign: $campaign,
                    contact: $contact,
                    evaluation: $evaluation,
                );
        }, 3);
    }

    private function applyEligible(
        Campaign $campaign,
        Contact $contact,
        CampaignEligibilityEvaluationResult $evaluation,
        bool $eagerProcess,
        Carbon|string|null $initialActionAt,
    ): CampaignEligibilityLifecycleResult {
        $openEnrollment = $this->openEnrollment($campaign, $contact);

        if ($openEnrollment instanceof CampaignEnrollment) {
            if ($this->wasPausedByEligibility($openEnrollment)) {
                $resumed = $this->resumeCampaignEnrollment->resumeEnrollment(
                    enrollment: $openEnrollment,
                    source: $evaluation->state,
                    reason: self::ELIGIBLE_AGAIN_REASON,
                    meta: $this->lifecycleMeta($campaign, $contact, $evaluation),
                );

                return new CampaignEligibilityLifecycleResult(
                    action: CampaignEligibilityLifecycleResult::RESUMED,
                    evaluation: $evaluation,
                    enrollment: $resumed,
                );
            }

            return new CampaignEligibilityLifecycleResult(
                action: CampaignEligibilityLifecycleResult::EXISTING_OPEN_ENROLLMENT,
                evaluation: $evaluation,
                enrollment: $openEnrollment,
            );
        }

        $hasHistoricalEnrollment = CampaignEnrollment::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('contact_id', $contact->getKey())
            ->exists();

        if ($hasHistoricalEnrollment) {
            if (! $evaluation->becameEligible()
                || $evaluation->eligibilityCycle < 2
                || $campaign->reentry_policy !== Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN
            ) {
                return new CampaignEligibilityLifecycleResult(
                    action: CampaignEligibilityLifecycleResult::REENTRY_BLOCKED,
                    evaluation: $evaluation,
                    meta: [
                        'reentry_policy' => $campaign->reentry_policy,
                        'eligibility_cycle' => $evaluation->eligibilityCycle,
                    ],
                );
            }
        }

        try {
            $enrollment = $this->enrollContactInCampaign->handle(
                contact: $contact,
                campaignKey: (string) $campaign->key,
                source: $evaluation->state,
                meta: [
                    'eligibility' => $this->lifecycleMeta(
                        campaign: $campaign,
                        contact: $contact,
                        evaluation: $evaluation,
                    ),
                ],
                startContext: [
                    'campaign_eligibility' => $this->lifecycleMeta(
                        campaign: $campaign,
                        contact: $contact,
                        evaluation: $evaluation,
                    ),
                ],
                entryKey: $this->entryKey($campaign, $contact, $evaluation),
                eagerProcess: $eagerProcess,
                initialActionAt: $initialActionAt,
            );
        } catch (CampaignUnavailableForEnrollmentException $exception) {
            if ($exception->reason !== CampaignUnavailableForEnrollmentException::REASON_FAMILY_BLOCKED) {
                throw $exception;
            }

            return new CampaignEligibilityLifecycleResult(
                action: CampaignEligibilityLifecycleResult::FAMILY_BLOCKED,
                evaluation: $evaluation,
                meta: [
                    'family_key' => $exception->familyKey,
                    'campaign_priority' => $exception->campaignPriority,
                    'blocking_campaign_key' => $exception->blockingCampaignKey,
                    'blocking_priority' => $exception->blockingPriority,
                    'blocking_enrollment_id' => $exception->blockingEnrollmentId,
                ],
            );
        }

        return new CampaignEligibilityLifecycleResult(
            action: CampaignEligibilityLifecycleResult::ENROLLED,
            evaluation: $evaluation,
            enrollment: $enrollment,
        );
    }

    private function applyIneligible(
        Campaign $campaign,
        Contact $contact,
        CampaignEligibilityEvaluationResult $evaluation,
    ): CampaignEligibilityLifecycleResult {
        $openEnrollment = $this->openEnrollment($campaign, $contact);

        if (! $openEnrollment instanceof CampaignEnrollment) {
            return new CampaignEligibilityLifecycleResult(
                action: CampaignEligibilityLifecycleResult::NO_OPEN_ENROLLMENT,
                evaluation: $evaluation,
            );
        }

        if ($campaign->ineligible_behavior === Campaign::INELIGIBLE_CONTINUE) {
            return new CampaignEligibilityLifecycleResult(
                action: CampaignEligibilityLifecycleResult::CONTINUED,
                evaluation: $evaluation,
                enrollment: $openEnrollment,
            );
        }

        if ($campaign->ineligible_behavior === Campaign::INELIGIBLE_PAUSE) {
            if ($openEnrollment->runtimeStatus() === MessageChainEnrollment::STATUS_PAUSED) {
                return new CampaignEligibilityLifecycleResult(
                    action: CampaignEligibilityLifecycleResult::EXISTING_OPEN_ENROLLMENT,
                    evaluation: $evaluation,
                    enrollment: $openEnrollment,
                );
            }

            $paused = $this->pauseCampaignEnrollment->pauseEnrollment(
                enrollment: $openEnrollment,
                source: $evaluation->state,
                reason: self::INELIGIBLE_REASON,
                skipPendingMessages: true,
                meta: $this->lifecycleMeta($campaign, $contact, $evaluation),
            );

            return new CampaignEligibilityLifecycleResult(
                action: CampaignEligibilityLifecycleResult::PAUSED,
                evaluation: $evaluation,
                enrollment: $paused,
            );
        }

        if ($campaign->ineligible_behavior === Campaign::INELIGIBLE_CANCEL) {
            $cancelled = $this->cancelCampaignEnrollment->cancelEnrollment(
                enrollment: $openEnrollment,
                source: $evaluation->state,
                reason: self::INELIGIBLE_REASON,
                skipPendingMessages: true,
                meta: $this->lifecycleMeta($campaign, $contact, $evaluation),
            );

            return new CampaignEligibilityLifecycleResult(
                action: CampaignEligibilityLifecycleResult::CANCELLED,
                evaluation: $evaluation,
                enrollment: $cancelled,
            );
        }

        return new CampaignEligibilityLifecycleResult(
            action: CampaignEligibilityLifecycleResult::SKIPPED_INVALID_CONFIGURATION,
            evaluation: $evaluation,
            enrollment: $openEnrollment,
        );
    }

    private function openEnrollment(
        Campaign $campaign,
        Contact $contact,
    ): ?CampaignEnrollment {
        return CampaignEnrollment::query()
            ->with('messageChainEnrollment')
            ->where('campaign_id', $campaign->getKey())
            ->where('contact_id', $contact->getKey())
            ->whereNotNull('message_chain_enrollment_id')
            ->whereHas(
                'messageChainEnrollment',
                fn ($query) => $query->whereIn('status', [
                    MessageChainEnrollment::STATUS_ACTIVE,
                    MessageChainEnrollment::STATUS_PAUSED,
                ]),
            )
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function wasPausedByEligibility(
        CampaignEnrollment $enrollment,
    ): bool {
        if ($enrollment->runtimeStatus() !== MessageChainEnrollment::STATUS_PAUSED) {
            return false;
        }

        return data_get(
            $enrollment->meta,
            'lifecycle.last_pause.reason',
        ) === self::INELIGIBLE_REASON;
    }

    private function entryKey(
        Campaign $campaign,
        Contact $contact,
        CampaignEligibilityEvaluationResult $evaluation,
    ): string {
        return implode(':', [
            'campaign_eligibility',
            (int) $campaign->getKey(),
            (int) $contact->getKey(),
            'cycle',
            $evaluation->eligibilityCycle,
        ]);
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
<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Data\CampaignEligibilityEvaluationResult;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEligibilityState;
use App\Modules\Campaigns\Services\CampaignEligibilityEvaluator;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EvaluateCampaignEligibilityAction
{
    public function __construct(
        private readonly CampaignEligibilityEvaluator $evaluator,
    ) {}

    public function handle(
        Campaign $campaign,
        Contact $contact,
        Carbon|string|null $at = null,
    ): CampaignEligibilityEvaluationResult {
        $evaluatedAt = ($at instanceof Carbon
            ? $at->copy()
            : ($at !== null ? Carbon::parse($at) : Carbon::now())
        )->utc();

        $currentEligible = $this->evaluator->eligible($campaign, $contact);

        return DB::transaction(function () use (
            $campaign,
            $contact,
            $evaluatedAt,
            $currentEligible,
        ): CampaignEligibilityEvaluationResult {
            CampaignEligibilityState::query()->firstOrCreate(
                [
                    'campaign_id' => $campaign->getKey(),
                    'contact_id' => $contact->getKey(),
                ],
                [
                    'is_eligible' => false,
                    'eligibility_cycle' => 0,
                ],
            );

            $state = CampaignEligibilityState::query()
                ->where('campaign_id', $campaign->getKey())
                ->where('contact_id', $contact->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previousEligible = (bool) $state->is_eligible;
            $transition = $this->transition(
                previousEligible: $previousEligible,
                currentEligible: $currentEligible,
            );
            $eligibilityCycle = (int) $state->eligibility_cycle;

            if ($transition === CampaignEligibilityEvaluationResult::BECAME_ELIGIBLE) {
                $eligibilityCycle++;
            }

            $attributes = [
                'is_eligible' => $currentEligible,
                'eligibility_cycle' => $eligibilityCycle,
                'last_evaluated_at' => $evaluatedAt,
            ];

            if ($transition === CampaignEligibilityEvaluationResult::BECAME_ELIGIBLE) {
                $attributes['became_eligible_at'] = $evaluatedAt;
            }

            if ($transition === CampaignEligibilityEvaluationResult::BECAME_INELIGIBLE) {
                $attributes['became_ineligible_at'] = $evaluatedAt;
            }

            $state->forceFill($attributes)->save();

            return new CampaignEligibilityEvaluationResult(
                state: $state->refresh(),
                previousEligible: $previousEligible,
                currentEligible: $currentEligible,
                transition: $transition,
                eligibilityCycle: $eligibilityCycle,
            );
        }, 3);
    }

    private function transition(
        bool $previousEligible,
        bool $currentEligible,
    ): string {
        if (! $previousEligible && $currentEligible) {
            return CampaignEligibilityEvaluationResult::BECAME_ELIGIBLE;
        }

        if ($previousEligible && ! $currentEligible) {
            return CampaignEligibilityEvaluationResult::BECAME_INELIGIBLE;
        }

        return $currentEligible
            ? CampaignEligibilityEvaluationResult::UNCHANGED_ELIGIBLE
            : CampaignEligibilityEvaluationResult::UNCHANGED_INELIGIBLE;
    }
}
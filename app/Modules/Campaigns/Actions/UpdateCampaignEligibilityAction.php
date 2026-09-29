<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Validation\ValidationException;

final class UpdateCampaignEligibilityAction
{
    /**
     * @param array<string, mixed> $filter
     */
    public function handle(
        Campaign $campaign,
        array $filter,
        string $enrollmentMode,
        string $reentryPolicy,
        string $ineligibleBehavior,
    ): Campaign {
        if ($campaign->usesRecurringAllocation()
            && $enrollmentMode === Campaign::ENROLLMENT_MODE_AUTOMATIC
            && $ineligibleBehavior === Campaign::INELIGIBLE_PAUSE
        ) {
            throw ValidationException::withMessages([
                'ineligible_behavior' => 'Recurring allocation can keep membership active or cancel it when eligibility ends.',
            ]);
        }

        $criteria = collect($filter)
            ->except(Campaign::ELIGIBILITY_EXCLUSIONS_KEY)
            ->filter(fn (mixed $values): bool => is_array($values) && $values !== [])
            ->all();

        if (
            $enrollmentMode === Campaign::ENROLLMENT_MODE_AUTOMATIC
            && $criteria === []
        ) {
            throw ValidationException::withMessages([
                'eligibility_criteria' => 'Automatic enrollment requires at least one eligibility condition.',
            ]);
        }

        $campaign->forceFill([
            'eligibility_filter' => $filter,
            'enrollment_mode' => $enrollmentMode,
            'reentry_policy' => $reentryPolicy,
            'ineligible_behavior' => $ineligibleBehavior,
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        return $campaign->refresh();
    }
}
<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Validation\ValidationException;

final class UpdateCampaignEligibilityAction
{
    /**
     * @param array<string, array<int, string>> $criteria
     */
    public function handle(
        Campaign $campaign,
        array $criteria,
        string $enrollmentMode,
        string $reentryPolicy,
        string $ineligibleBehavior,
    ): Campaign {
        if (
            $enrollmentMode === Campaign::ENROLLMENT_MODE_AUTOMATIC
            && $criteria === []
        ) {
            throw ValidationException::withMessages([
                'eligibility_criteria' => 'Automatic enrollment requires at least one eligibility condition.',
            ]);
        }

        $campaign->forceFill([
            'eligibility_filter' => $criteria,
            'enrollment_mode' => $enrollmentMode,
            'reentry_policy' => $reentryPolicy,
            'ineligible_behavior' => $ineligibleBehavior,
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        return $campaign->refresh();
    }
}
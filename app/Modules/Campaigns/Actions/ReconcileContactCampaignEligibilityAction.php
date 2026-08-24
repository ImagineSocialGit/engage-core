<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Data\CampaignEligibilityLifecycleResult;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignEligibilityReevaluationGuard;
use App\Modules\Campaigns\Services\CampaignEligibilityReconciliationPlanner;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Carbon;

final class ReconcileContactCampaignEligibilityAction
{
    public function __construct(
        private readonly ApplyAutomaticCampaignEligibilityAction $applyEligibility,
        private readonly CampaignEligibilityReevaluationGuard $guard,
        private readonly CampaignEligibilityReconciliationPlanner $planner,
    ) {}

    /**
     * @return array<int, CampaignEligibilityLifecycleResult>
     */
    public function handle(
        Contact $contact,
        Carbon|string|null $at = null,
    ): array {
        if (! $this->guard->mayEvaluate($contact)) {
            return [];
        }

        $campaigns = $this->planner->orderForContact(
            contact: $contact,
            campaigns: Campaign::query()
                ->active()
                ->where('enrollment_mode', Campaign::ENROLLMENT_MODE_AUTOMATIC)
                ->orderBy('id')
                ->get(),
        );

        $results = [];

        foreach ($campaigns as $campaign) {
            $results[] = $this->applyEligibility->handle(
                campaign: $campaign,
                contact: $contact,
                at: $at,
            );
        }

        return $results;
    }
}
<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationMessageExclusion;
use App\Modules\Campaigns\Services\CampaignMessageStepResolver;
use App\Modules\Core\Models\Contact;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RemoveContactCampaignAllocationMessageExclusionAction
{
    public function __construct(
        private readonly CampaignMessageStepResolver $messageSteps,
    ) {}

    public function handle(
        Contact $contact,
        string $campaignKey,
        string $messageStepKey,
    ): bool {
        $campaignKey = trim($campaignKey);
        $messageStepKey = trim($messageStepKey);

        if ($campaignKey === '' || $messageStepKey === '') {
            throw new InvalidArgumentException(
                'Removing an allocation message exclusion requires Campaign and message identity.',
            );
        }

        return DB::transaction(function () use (
            $contact,
            $campaignKey,
            $messageStepKey,
        ): bool {
            $campaign = Campaign::query()
                ->where('key', $campaignKey)
                ->lockForUpdate()
                ->first();

            if (! $campaign instanceof Campaign
                || $campaign->execution_strategy
                    !== Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION
            ) {
                throw new InvalidArgumentException(
                    "Recurring-allocation Campaign [{$campaignKey}] is required.",
                );
            }

            $this->messageSteps->activeStep($campaign, $messageStepKey);

            return CampaignAllocationMessageExclusion::query()
                ->where('contact_id', $contact->getKey())
                ->where('campaign_id', $campaign->getKey())
                ->where('message_step_key', $messageStepKey)
                ->delete() > 0;
        }, 3);
    }
}
<?php

namespace App\Modules\Campaigns\Actions;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationMessageExclusion;
use App\Modules\Campaigns\Services\CampaignMessageStepResolver;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ExcludeContactFromCampaignAllocationMessageAction
{
    public function __construct(
        private readonly CampaignMessageStepResolver $messageSteps,
    ) {}

    public function handle(
        Contact $contact,
        string $campaignKey,
        string $messageStepKey,
        ?Model $source = null,
        ?User $excludedBy = null,
        ?string $reason = null,
    ): CampaignAllocationMessageExclusion {
        $campaignKey = trim($campaignKey);
        $messageStepKey = trim($messageStepKey);

        if ($campaignKey === '' || $messageStepKey === '') {
            throw new InvalidArgumentException(
                'Allocation message exclusion requires Campaign and message identity.',
            );
        }

        return DB::transaction(function () use (
            $contact,
            $campaignKey,
            $messageStepKey,
            $source,
            $excludedBy,
            $reason,
        ): CampaignAllocationMessageExclusion {
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

            return CampaignAllocationMessageExclusion::query()->updateOrCreate(
                [
                    'contact_id' => $contact->getKey(),
                    'campaign_id' => $campaign->getKey(),
                    'message_step_key' => $messageStepKey,
                ],
                [
                    'source_type' => $source?->getMorphClass(),
                    'source_id' => $source?->getKey(),
                    'excluded_by' => $excludedBy?->getKey(),
                    'reason' => $this->reason($reason),
                ],
            );
        }, 3);
    }

    private function reason(?string $reason): ?string
    {
        if (! is_string($reason) || trim($reason) === '') {
            return null;
        }

        return mb_substr(trim($reason), 0, 255);
    }
}
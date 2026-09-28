<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Services\CampaignMessageStepResolver;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReenrollContactInCampaignAllocationAction
{
    public const REASON = 'campaign_allocation_reenrolled';

    public function __construct(
        private readonly EnrollContactInCampaignAllocationAction $enroll,
        private readonly CancelCampaignAllocationEnrollmentAction $cancel,
        private readonly CampaignMessageStepResolver $messageSteps,
    ) {}

    /** @param array<string, mixed>|null $meta */
    public function handle(
        Contact $contact,
        string $campaignKey,
        string $startMessageStepKey,
        string $entryKey,
        ?Model $source = null,
        ?array $meta = null,
    ): CampaignAllocationEnrollment {
        $campaignKey = trim($campaignKey);
        $startMessageStepKey = trim($startMessageStepKey);
        $entryKey = trim($entryKey);

        if ($campaignKey === '' || $startMessageStepKey === '' || $entryKey === '') {
            throw new InvalidArgumentException(
                'Recurring allocation re-enrollment requires Campaign, start message, and stable entry identity.',
            );
        }

        return DB::transaction(function () use (
            $contact,
            $campaignKey,
            $startMessageStepKey,
            $entryKey,
            $source,
            $meta,
        ): CampaignAllocationEnrollment {
            $campaign = Campaign::query()
                ->where('key', $campaignKey)
                ->lockForUpdate()
                ->first();

            if (! $campaign instanceof Campaign
                || ! $campaign->isActive()
                || $campaign->execution_strategy
                    !== Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION
            ) {
                throw new InvalidArgumentException(
                    "Active recurring-allocation Campaign [{$campaignKey}] is required.",
                );
            }

            if ($campaign->usesAutomaticEnrollment()) {
                throw new InvalidArgumentException(
                    "Recurring-allocation Campaign [{$campaignKey}] must use manual enrollment.",
                );
            }

            if (is_string($campaign->family_key) && trim($campaign->family_key) !== '') {
                throw new InvalidArgumentException(
                    "Recurring-allocation Campaign [{$campaignKey}] cannot use Campaign-family arbitration yet.",
                );
            }

            $this->messageSteps->activeStep($campaign, $startMessageStepKey);

            $dedupeKey = $this->enroll->dedupeKey(
                $campaign,
                $contact,
                $entryKey,
            );
            $existingEntry = CampaignAllocationEnrollment::query()
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();

            if ($existingEntry instanceof CampaignAllocationEnrollment) {
                return $existingEntry;
            }

            $activeEnrollments = CampaignAllocationEnrollment::query()
                ->where('campaign_id', $campaign->getKey())
                ->where('contact_id', $contact->getKey())
                ->where('status', CampaignAllocationEnrollment::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($activeEnrollments as $activeEnrollment) {
                $this->cancel->cancelEnrollment(
                    enrollment: $activeEnrollment,
                    source: $source,
                    reason: self::REASON,
                    skipPendingMessages: true,
                    meta: array_replace_recursive($meta ?? [], [
                        'replacement_entry_key' => $entryKey,
                        'replacement_start_message_step_key' => $startMessageStepKey,
                    ]),
                );
            }

            return $this->enroll->handle(
                contact: $contact,
                campaignKey: $campaignKey,
                source: $source,
                meta: array_replace_recursive($meta ?? [], [
                    'reenrollment' => [
                        'reason' => self::REASON,
                        'replaced_enrollment_ids' => $activeEnrollments
                            ->modelKeys(),
                    ],
                ]),
                startMessageStepKey: $startMessageStepKey,
                entryKey: $entryKey,
            );
        }, 3);
    }
}
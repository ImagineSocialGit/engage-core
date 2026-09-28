<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Services\CampaignMessageStepResolver;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReenrollContactInCampaignAction
{
    public const REASON = 'campaign_reenrolled_from_message';

    public function __construct(
        private readonly EnrollContactInCampaignAction $enroll,
        private readonly CancelCampaignEnrollmentAction $cancel,
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
    ): CampaignEnrollment {
        $campaignKey = trim($campaignKey);
        $startMessageStepKey = trim($startMessageStepKey);
        $entryKey = trim($entryKey);

        if ($campaignKey === '' || $startMessageStepKey === '' || $entryKey === '') {
            throw new InvalidArgumentException(
                'Campaign re-enrollment requires Campaign, start message, and stable entry identity.',
            );
        }

        return DB::transaction(function () use (
            $contact,
            $campaignKey,
            $startMessageStepKey,
            $entryKey,
            $source,
            $meta,
        ): CampaignEnrollment {
            $campaign = Campaign::query()
                ->where('key', $campaignKey)
                ->lockForUpdate()
                ->first();

            if (! $campaign instanceof Campaign
                || ! $campaign->isActive()
                || $campaign->execution_strategy
                    !== Campaign::EXECUTION_STRATEGY_SEQUENCE
            ) {
                throw new InvalidArgumentException(
                    "Active sequential Campaign [{$campaignKey}] is required.",
                );
            }

            $this->messageSteps->activeStep($campaign, $startMessageStepKey);

            $existingEntry = CampaignEnrollment::query()
                ->with('messageChainEnrollment')
                ->where('campaign_id', $campaign->getKey())
                ->where('contact_id', $contact->getKey())
                ->where('start_context->entry_key', $entryKey)
                ->lockForUpdate()
                ->first();

            if ($existingEntry instanceof CampaignEnrollment) {
                return $existingEntry;
            }

            $openEnrollments = CampaignEnrollment::query()
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
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($openEnrollments as $openEnrollment) {
                $this->cancel->cancelEnrollment(
                    enrollment: $openEnrollment,
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
                        'replaced_enrollment_ids' => $openEnrollments
                            ->modelKeys(),
                    ],
                ]),
                startContext: [
                    'source' => 'campaign_reenrollment',
                    'start_message_step_key' => $startMessageStepKey,
                ],
                entryKey: $entryKey,
                eagerProcess: true,
                startStepKey: $startMessageStepKey,
            );
        }, 3);
    }
}
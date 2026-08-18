<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeactivateCampaignAction
{
    public const REASON = 'campaign_deactivated';

    private const CAMPAIGN_PRESET_CHAIN_SOURCE = 'campaign_preset_bridge';

    public function __construct(
        private readonly CancelCampaignEnrollmentAction $cancelCampaignEnrollment,
        private readonly SkipScheduledMessagesAction $skipScheduledMessages,
    ) {}

    /**
     * @param array<string, mixed> $meta
     * @return array{
     *     campaign_id: int,
     *     campaign_key: string,
     *     previous_status: string,
     *     current_status: string,
     *     status_changed: bool,
     *     enrollments_cancelled: int,
     *     scheduled_messages_skipped: int
     * }
     */
    public function handle(
        Campaign $campaign,
        ?Model $actor = null,
        string $source = 'application',
        array $meta = [],
    ): array {
        return DB::transaction(function () use ($campaign, $actor, $source, $meta): array {
            $lockedCampaign = Campaign::query()
                ->lockForUpdate()
                ->findOrFail($campaign->getKey());

            $now = Carbon::now();
            $previousStatus = (string) $lockedCampaign->status;
            $statusChanged = $lockedCampaign->isActive();
            $source = $this->source($source);

            if ($statusChanged) {
                $lockedCampaign->forceFill([
                    'status' => Campaign::STATUS_INACTIVE,
                    'meta' => $this->campaignMeta(
                        campaign: $lockedCampaign,
                        previousStatus: $previousStatus,
                        currentStatus: Campaign::STATUS_INACTIVE,
                        actor: $actor,
                        source: $source,
                        meta: $meta,
                        changedAt: $now,
                    ),
                ])->save();
            }

            $this->inactivateExclusivePresetBridgeChain($lockedCampaign);

            $linkedEnrollments = CampaignEnrollment::query()
                ->with('messageChainEnrollment')
                ->where('campaign_id', $lockedCampaign->getKey())
                ->whereNotNull('message_chain_enrollment_id')
                ->whereHas(
                    'messageChainEnrollment',
                    fn ($query) => $query->whereIn('status', [
                        MessageChainEnrollment::STATUS_ACTIVE,
                        MessageChainEnrollment::STATUS_PAUSED,
                    ]),
                )
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $scheduledMessagesSkipped = 0;

            foreach ($linkedEnrollments as $enrollment) {
                $scheduledMessagesSkipped += ScheduledMessage::query()
                    ->where('message_chain_enrollment_id', $enrollment->message_chain_enrollment_id)
                    ->where('status', ScheduledMessage::STATUS_PENDING)
                    ->count();

                $this->cancelCampaignEnrollment->cancelEnrollment(
                    enrollment: $enrollment,
                    source: $actor,
                    reason: self::REASON,
                    skipPendingMessages: true,
                    meta: array_replace_recursive([
                        'lifecycle_source' => $source,
                    ], $meta),
                );
            }

            // Transitional shutdown cleanup for pre-F5 rows only. No active client
            // Campaign state depends on this path; F7 removes it with legacy runtime.
            $legacyEnrollments = CampaignEnrollment::query()
                ->where('campaign_id', $lockedCampaign->getKey())
                ->whereNull('message_chain_enrollment_id')
                ->whereIn('status', [
                    CampaignEnrollment::STATUS_ACTIVE,
                    CampaignEnrollment::STATUS_PAUSED,
                ])
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $legacyPendingMessageCount = $legacyEnrollments->isEmpty()
                ? 0
                : ScheduledMessage::query()
                    ->where('status', ScheduledMessage::STATUS_PENDING)
                    ->where(function ($query) use ($lockedCampaign): void {
                        $query
                            ->where('meta->campaign_id', $lockedCampaign->getKey())
                            ->orWhere('meta->campaign_key', $lockedCampaign->key);
                    })
                    ->count();

            foreach ($legacyEnrollments as $enrollment) {
                $this->cancelCampaignEnrollment->cancelEnrollment(
                    enrollment: $enrollment,
                    source: $actor,
                    reason: self::REASON,
                    skipPendingMessages: true,
                    meta: array_replace_recursive([
                        'lifecycle_source' => $source,
                    ], $meta),
                );
            }

            if ($legacyEnrollments->isNotEmpty()) {
                $this->skipScheduledMessages->forMetaValue(
                    key: 'campaign_id',
                    value: $lockedCampaign->getKey(),
                    reason: self::REASON,
                );

                $this->skipScheduledMessages->forMetaValue(
                    key: 'campaign_key',
                    value: $lockedCampaign->key,
                    reason: self::REASON,
                );

                $scheduledMessagesSkipped += $legacyPendingMessageCount;
            }

            return [
                'campaign_id' => (int) $lockedCampaign->getKey(),
                'campaign_key' => (string) $lockedCampaign->key,
                'previous_status' => $previousStatus,
                'current_status' => (string) $lockedCampaign->status,
                'status_changed' => $statusChanged,
                'enrollments_cancelled' => $linkedEnrollments->count() + $legacyEnrollments->count(),
                'scheduled_messages_skipped' => $scheduledMessagesSkipped,
            ];
        }, 3);
    }

    private function inactivateExclusivePresetBridgeChain(Campaign $campaign): void
    {
        if (! is_numeric($campaign->message_chain_id) || (int) $campaign->message_chain_id < 1) {
            return;
        }

        $chain = MessageChain::query()
            ->whereKey((int) $campaign->message_chain_id)
            ->lockForUpdate()
            ->first();

        if (! $chain instanceof MessageChain
            || $chain->status !== MessageChain::STATUS_ACTIVE
            || $chain->source !== self::CAMPAIGN_PRESET_CHAIN_SOURCE
        ) {
            return;
        }

        $sharedByAnotherActiveCampaign = Campaign::query()
            ->where('message_chain_id', $chain->getKey())
            ->where('id', '!=', $campaign->getKey())
            ->where('status', Campaign::STATUS_ACTIVE)
            ->exists();

        if ($sharedByAnotherActiveCampaign) {
            return;
        }

        $chain->forceFill([
            'status' => MessageChain::STATUS_INACTIVE,
        ])->save();
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function campaignMeta(
        Campaign $campaign,
        string $previousStatus,
        string $currentStatus,
        ?Model $actor,
        string $source,
        array $meta,
        Carbon $changedAt,
    ): array {
        $existingMeta = is_array($campaign->meta) ? $campaign->meta : [];

        return array_replace_recursive($existingMeta, [
            'lifecycle' => [
                'last_status_change' => [
                    'reason' => self::REASON,
                    'source' => $source,
                    'actor_type' => $actor?->getMorphClass(),
                    'actor_id' => $actor?->getKey(),
                    'previous_status' => $previousStatus,
                    'current_status' => $currentStatus,
                    'meta' => $meta,
                    'changed_at' => $changedAt->toISOString(),
                ],
            ],
        ]);
    }

    private function source(string $source): string
    {
        $source = trim($source);

        return $source !== '' ? $source : 'application';
    }
}
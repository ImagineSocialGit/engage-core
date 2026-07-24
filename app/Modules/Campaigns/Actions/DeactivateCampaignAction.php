<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DeactivateCampaignAction
{
    public const REASON = 'campaign_deactivated';

    public function __construct(
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

            $enrollments = CampaignEnrollment::query()
                ->where(function ($query) use ($lockedCampaign): void {
                    $query
                        ->where('campaign_id', $lockedCampaign->getKey())
                        ->orWhere('campaign_key', $lockedCampaign->key);
                })
                ->whereIn('status', [
                    CampaignEnrollment::STATUS_ACTIVE,
                    CampaignEnrollment::STATUS_PAUSED,
                ])
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            foreach ($enrollments as $enrollment) {
                $enrollment->forceFill([
                    'status' => CampaignEnrollment::STATUS_CANCELLED,
                    'cancelled_at' => $enrollment->cancelled_at ?? $now,
                    'exited_at' => $enrollment->exited_at ?? $now,
                    'exit_reason' => CampaignEnrollment::EXIT_REASON_CAMPAIGN_DEACTIVATED,
                    'meta' => $this->enrollmentMeta(
                        enrollment: $enrollment,
                        campaign: $lockedCampaign,
                        actor: $actor,
                        source: $source,
                        meta: $meta,
                        cancelledAt: $now,
                    ),
                ])->save();
            }

            $scheduledMessagesSkipped = $this->skipScheduledMessages->forMetaValue(
                key: 'campaign_id',
                value: $lockedCampaign->getKey(),
                reason: self::REASON,
            );

            $scheduledMessagesSkipped += $this->skipScheduledMessages->forMetaValue(
                key: 'campaign_key',
                value: $lockedCampaign->key,
                reason: self::REASON,
            );

            return [
                'campaign_id' => (int) $lockedCampaign->getKey(),
                'campaign_key' => (string) $lockedCampaign->key,
                'previous_status' => $previousStatus,
                'current_status' => (string) $lockedCampaign->status,
                'status_changed' => $statusChanged,
                'enrollments_cancelled' => $enrollments->count(),
                'scheduled_messages_skipped' => $scheduledMessagesSkipped,
            ];
        });
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

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function enrollmentMeta(
        CampaignEnrollment $enrollment,
        Campaign $campaign,
        ?Model $actor,
        string $source,
        array $meta,
        Carbon $cancelledAt,
    ): array {
        $existingMeta = is_array($enrollment->meta) ? $enrollment->meta : [];

        return array_replace_recursive($existingMeta, [
            'cancellation' => [
                'reason' => self::REASON,
                'source' => $source,
                'source_type' => $actor?->getMorphClass(),
                'source_id' => $actor?->getKey(),
                'campaign_id' => $campaign->getKey(),
                'campaign_key' => $campaign->key,
                'skipped_pending_messages' => true,
                'meta' => $meta,
                'cancelled_at' => $cancelledAt->toISOString(),
            ],
        ]);
    }

    private function source(string $source): string
    {
        $source = trim($source);

        return $source !== '' ? $source : 'application';
    }
}
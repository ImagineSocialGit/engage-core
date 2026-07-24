<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ActivateCampaignAction
{
    public const REASON = 'campaign_activated';

    /**
     * @param array<string, mixed> $meta
     * @return array{
     *     campaign_id: int,
     *     campaign_key: string,
     *     previous_status: string,
     *     current_status: string,
     *     status_changed: bool
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

            if ($lockedCampaign->status === Campaign::STATUS_ARCHIVED) {
                throw new InvalidArgumentException(
                    "Archived Campaign [{$lockedCampaign->key}] cannot be activated."
                );
            }

            $previousStatus = (string) $lockedCampaign->status;
            $statusChanged = ! $lockedCampaign->isActive();

            if ($statusChanged) {
                $now = Carbon::now();
                $existingMeta = is_array($lockedCampaign->meta) ? $lockedCampaign->meta : [];
                $source = trim($source) !== '' ? trim($source) : 'application';

                $lockedCampaign->forceFill([
                    'status' => Campaign::STATUS_ACTIVE,
                    'meta' => array_replace_recursive($existingMeta, [
                        'lifecycle' => [
                            'last_status_change' => [
                                'reason' => self::REASON,
                                'source' => $source,
                                'actor_type' => $actor?->getMorphClass(),
                                'actor_id' => $actor?->getKey(),
                                'previous_status' => $previousStatus,
                                'current_status' => Campaign::STATUS_ACTIVE,
                                'meta' => $meta,
                                'changed_at' => $now->toISOString(),
                            ],
                        ],
                    ]),
                ])->save();
            }

            return [
                'campaign_id' => (int) $lockedCampaign->getKey(),
                'campaign_key' => (string) $lockedCampaign->key,
                'previous_status' => $previousStatus,
                'current_status' => (string) $lockedCampaign->status,
                'status_changed' => $statusChanged,
            ];
        });
    }
}
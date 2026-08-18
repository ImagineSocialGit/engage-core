<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Collection;

class CampaignWorkspacePresenter
{
    public const BUILDER_STAGE_KEYS = [
        'start',
        'schedule',
        'messages',
        'review',
    ];

    /**
     * Current CampaignStep/CampaignStepVariant reads are a presentation adapter over
     * the transitional runtime. Views consume this stable business-facing projection
     * so the later MessageChain cutover can replace the source without redesigning
     * the Campaign workspace or Builder shell.
     *
     * @return array{
     *     active_enrollment_count: int,
     *     pending_message_count: int,
     *     message_step_count: int,
     *     message_count: int,
     *     channels: array<int, string>,
     *     builder_stages: array<int, array{key: string, state: string, editable: bool}>
     * }
     */
    public function forCampaign(Campaign $campaign): array
    {
        $campaign->loadMissing([
            'steps' => fn ($query) => $query
                ->active()
                ->with([
                    'variants' => fn ($variantQuery) => $variantQuery
                        ->active()
                        ->orderBy('sort_order')
                        ->orderBy('id'),
                ])
                ->orderBy('step_number'),
        ]);

        /** @var Collection<int, CampaignStep> $steps */
        $steps = $campaign->steps->values();

        /** @var Collection<int, CampaignStepVariant> $variants */
        $variants = $steps
            ->flatMap(fn (CampaignStep $step): Collection => $step->variants)
            ->values();

        $channels = $variants
            ->pluck('channel')
            ->filter(fn (mixed $channel): bool => is_string($channel) && trim($channel) !== '')
            ->map(fn (string $channel): string => $this->normalizeSegment($channel))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $messageStepCount = $steps->count();
        $messageCount = $variants->count();

        return [
            'active_enrollment_count' => $this->activeEnrollmentCount($campaign),
            'pending_message_count' => $this->pendingScheduledMessageCount($campaign),
            'message_step_count' => $messageStepCount,
            'message_count' => $messageCount,
            'channels' => $channels,
            'builder_stages' => $this->builderStages(
                campaign: $campaign,
                messageStepCount: $messageStepCount,
                messageCount: $messageCount,
            ),
        ];
    }

    /**
     * @return array<int, array{key: string, state: string, editable: bool}>
     */
    private function builderStages(
        Campaign $campaign,
        int $messageStepCount,
        int $messageCount,
    ): array {
        return [
            [
                'key' => 'start',
                'state' => 'not_managed',
                'editable' => false,
            ],
            [
                'key' => 'schedule',
                'state' => $messageStepCount > 0 ? 'configured' : 'empty',
                'editable' => false,
            ],
            [
                'key' => 'messages',
                'state' => $messageCount > 0 ? 'configured' : 'empty',
                'editable' => true,
            ],
            [
                'key' => 'review',
                'state' => $campaign->isActive() ? 'active' : 'inactive',
                'editable' => false,
            ],
        ];
    }

    private function activeEnrollmentCount(Campaign $campaign): int
    {
        return CampaignEnrollment::query()
            ->where(function ($query) use ($campaign): void {
                $query
                    ->where('campaign_id', $campaign->getKey())
                    ->orWhere('campaign_key', $campaign->key);
            })
            ->whereIn('status', [
                CampaignEnrollment::STATUS_ACTIVE,
                CampaignEnrollment::STATUS_PAUSED,
            ])
            ->count();
    }

    private function pendingScheduledMessageCount(Campaign $campaign): int
    {
        return ScheduledMessage::query()
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->where(function ($query) use ($campaign): void {
                $query
                    ->where('meta->campaign_id', $campaign->getKey())
                    ->orWhere('meta->campaign_key', $campaign->key);
            })
            ->count();
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
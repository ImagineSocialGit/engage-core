<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CampaignWorkspacePresenter
{
    public const BUILDER_STAGE_KEYS = ['start', 'schedule', 'messages', 'review'];

    public function __construct(
        private readonly CampaignScheduleAuthoringPresenter $schedulePresenter,
    ) {}

    /**
     * @return array{
     *     active_enrollment_count: int,
     *     pending_message_count: int,
     *     message_step_count: int,
     *     message_count: int,
     *     channels: array<int, string>,
     *     message_chain_version_id: int|null,
     *     message_chain_version: int|null,
     *     schedule_steps: array<int, array{
     *         id: int,
     *         step_number: int,
     *         name: string,
     *         timing: string,
     *         channels: array<int, string>,
     *         message_count: int
     *     }>,
     *     builder_stages: array<int, array{key: string, state: string, editable: bool}>
     * }
     */
    /** @param array<string, mixed>|null $schedule */
    public function forCampaign(Campaign $campaign, ?array $schedule = null): array
    {
        $schedule ??= $this->schedulePresenter->forCampaign($campaign);

        if ($schedule['editable']) {
            $scheduleSteps = $schedule['steps'];
            $channels = collect($scheduleSteps)
                ->flatMap(fn (array $step): array => $step['channels'])
                ->unique()
                ->sort()
                ->values()
                ->all();
            $messageStepCount = count($scheduleSteps);
            $messageCount = (int) collect($scheduleSteps)->sum('message_count');

            return [
                'active_enrollment_count' => $this->activeEnrollmentCount($campaign),
                'pending_message_count' => $this->pendingScheduledMessageCount($campaign),
                'message_step_count' => $messageStepCount,
                'message_count' => $messageCount,
                'channels' => $channels,
                'message_chain_version_id' => $schedule['message_chain_version_id'],
                'message_chain_version' => $schedule['version'],
                'schedule_steps' => $scheduleSteps,
                'builder_stages' => $this->builderStages(
                    campaign: $campaign,
                    messageStepCount: $messageStepCount,
                    messageCount: $messageCount,
                    scheduleEditable: true,
                ),
            ];
        }

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
            'message_chain_version_id' => null,
            'message_chain_version' => null,
            'schedule_steps' => $this->scheduleSteps($steps),
            'builder_stages' => $this->builderStages(
                campaign: $campaign,
                messageStepCount: $messageStepCount,
                messageCount: $messageCount,
                scheduleEditable: false,
            ),
        ];
    }

    /**
     * @param Collection<int, CampaignStep> $steps
     * @return array<int, array{
     *     id: int,
     *     step_number: int,
     *     name: string,
     *     timing: string,
     *     channels: array<int, string>,
     *     message_count: int
     * }>
     */
    private function scheduleSteps(Collection $steps): array
    {
        return $steps
            ->map(function (CampaignStep $step): array {
                $channels = $step->variants
                    ->pluck('channel')
                    ->filter(fn (mixed $channel): bool => is_string($channel) && trim($channel) !== '')
                    ->map(fn (string $channel): string => $this->normalizeSegment($channel))
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();

                return [
                    'id' => (int) $step->getKey(),
                    'step_number' => (int) $step->step_number,
                    'name' => trim((string) $step->name) !== ''
                        ? (string) $step->name
                        : 'Message '.(int) $step->step_number,
                    'timing' => $this->timingLabel($step),
                    'channels' => $channels,
                    'message_count' => $step->variants->count(),
                ];
            })
            ->values()
            ->all();
    }

    private function timingLabel(CampaignStep $step): string
    {
        $criteria = is_array($step->criteria) ? $step->criteria : [];
        $timing = data_get($criteria, 'timing');

        if (! is_array($timing)) {
            $timing = data_get($criteria, 'schedule');
        }

        if (! is_array($timing) || $timing === []) {
            return (int) $step->step_number === 1
                ? 'Immediately after the Campaign starts'
                : 'Immediately after the previous step finishes';
        }

        $type = $this->normalizeSegment((string) ($timing['type'] ?? 'immediate'));

        if ($type === 'immediate') {
            return (int) $step->step_number === 1
                ? 'Immediately after the Campaign starts'
                : 'Immediately after the previous step finishes';
        }

        $duration = $this->durationLabel($timing);

        if ($type === 'delay') {
            $anchor = (int) $step->step_number === 1
                ? 'the Campaign starts'
                : 'the previous step finishes';

            return $duration !== null
                ? $duration.' after '.$anchor
                : 'After '.$anchor;
        }

        if ($type === 'anchored') {
            $anchor = trim((string) ($timing['anchor_key'] ?? 'the configured event'));
            $anchor = $anchor !== '' ? Str::headline($anchor) : 'the configured event';

            return $duration !== null
                ? $duration.' after '.$anchor
                : 'When '.$anchor.' occurs';
        }

        return Str::headline($type);
    }

    /** @param array<string, mixed> $timing */
    private function durationLabel(array $timing): ?string
    {
        foreach (['days' => 'day', 'hours' => 'hour', 'minutes' => 'minute', 'seconds' => 'second'] as $field => $unit) {
            if (! is_numeric($timing[$field] ?? null)) {
                continue;
            }

            $value = max(0, (int) $timing[$field]);

            return $value.' '.Str::plural($unit, $value);
        }

        return null;
    }

    /**
     * @return array<int, array{key: string, state: string, editable: bool}>
     */
    private function builderStages(
        Campaign $campaign,
        int $messageStepCount,
        int $messageCount,
        bool $scheduleEditable,
    ): array {
        return [
            [
                'key' => 'start',
                'state' => $campaign->usesAutomaticEnrollment()
                    && ! $campaign->hasEligibilityCriteria()
                        ? 'empty'
                        : 'configured',
                'editable' => true,
            ],
            [
                'key' => 'schedule',
                'state' => $messageStepCount > 0 ? 'configured' : 'empty',
                'editable' => $scheduleEditable,
            ],
            [
                'key' => 'messages',
                'state' => $messageCount > 0 ? 'configured' : 'empty',
                'editable' => true,
            ],
            [
                'key' => 'review',
                'state' => $campaign->isActive() ? 'active' : 'inactive',
                'editable' => true,
            ],
        ];
    }

    private function activeEnrollmentCount(Campaign $campaign): int
    {
        return CampaignEnrollment::query()
            ->where('campaign_id', $campaign->getKey())
            ->whereHas(
                'messageChainEnrollment',
                fn ($query) => $query->whereIn('status', [
                    MessageChainEnrollment::STATUS_ACTIVE,
                    MessageChainEnrollment::STATUS_PAUSED,
                ]),
            )
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
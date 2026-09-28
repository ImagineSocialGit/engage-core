<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Models\CampaignAllocationRun;
use App\Modules\Messaging\Models\MessageChainStep;
use InvalidArgumentException;
use RuntimeException;

final class CampaignAllocationRunPreviewService
{
    private const MAX_CANDIDATES_PER_MESSAGE = 250;

    public function __construct(
        private readonly CampaignMessageStepResolver $messageSteps,
        private readonly CampaignAllocationSettingsService $settings,
        private readonly CampaignAllocationCandidateSelector $candidates,
    ) {}

    /**
     * @return array{
     *   can_start: bool, reason: string|null, quota: int,
     *   messages: array<int, array{key: string, name: string, potential: int, capped: bool}>
     * }
     */
    public function forCampaign(Campaign $campaign): array
    {
        $settings = $this->settings->forCampaign($campaign);
        $quota = $settings['allocation_size_per_message'];
        $result = [
            'can_start' => false,
            'reason' => null,
            'quota' => $quota,
            'messages' => [],
        ];

        if (! $campaign->isActive() || ! $campaign->usesRecurringAllocation()) {
            $result['reason'] = 'Turn on this allocation Campaign before starting a run.';

            return $result;
        }

        if (is_string($campaign->family_key) && trim($campaign->family_key) !== '') {
            $result['reason'] = 'Allocation Campaigns cannot use Campaign-family arbitration.';

            return $result;
        }

        try {
            $steps = $this->messageSteps->activeSteps($campaign);
        } catch (InvalidArgumentException|RuntimeException) {
            $result['reason'] = 'Publish a message schedule before starting a run.';

            return $result;
        }

        if ($steps->isEmpty()) {
            $result['reason'] = 'Add an active message before starting a run.';

            return $result;
        }

        $latest = CampaignAllocationRun::query()
            ->where('campaign_id', $campaign->getKey())
            ->orderByDesc('id')
            ->first();

        if ($latest instanceof CampaignAllocationRun
            && in_array($latest->status, [
                CampaignAllocationRun::STATUS_SCHEDULED,
                CampaignAllocationRun::STATUS_RUNNING,
            ], true)
        ) {
            $result['reason'] = 'A run is already scheduled or running.';

            return $result;
        }

        if (! CampaignAllocationEnrollment::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('status', CampaignAllocationEnrollment::STATUS_ACTIVE)
            ->exists()
        ) {
            $result['reason'] = 'Enroll eligible leads before starting a run.';

            return $result;
        }

        $excluded = [];
        $limit = min($quota, self::MAX_CANDIDATES_PER_MESSAGE);

        foreach ($steps as $step) {
            if (! $step instanceof MessageChainStep) {
                continue;
            }

            $selected = $this->candidates->select(
                campaign: $campaign,
                runId: 0,
                step: $step,
                orderedSteps: $steps,
                cooldownDays: $settings['recipient_cooldown_days'],
                limit: $limit,
                excludeContactIds: $excluded,
            );

            foreach ($selected as $enrollment) {
                $excluded[] = (int) $enrollment->contact_id;
            }

            $result['messages'][] = [
                'key' => (string) $step->key,
                'name' => (string) $step->name,
                'potential' => $selected->count(),
                'capped' => $quota > $limit && $selected->count() === $limit,
            ];
        }

        $result['can_start'] = true;

        return $result;
    }
}
<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Models\CampaignAllocationRun;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Services\CampaignAllocationSettingsService;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateCampaignAllocationSettingsAction
{
    public function __construct(
        private readonly CampaignAllocationSettingsService $settings,
    ) {}

    /** @param array<string, mixed>|null $allocationSettings */
    public function handle(
        Campaign $campaign,
        string $executionStrategy,
        ?array $allocationSettings = null,
    ): Campaign {
        $executionStrategy = strtolower(trim($executionStrategy));

        if (! in_array($executionStrategy, Campaign::EXECUTION_STRATEGIES, true)) {
            throw ValidationException::withMessages([
                'execution_strategy' => 'Choose a supported Campaign execution strategy.',
            ]);
        }

        $normalizedSettings = $executionStrategy
            === Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION
                ? $this->settings->forPersistence($allocationSettings ?? [])
                : null;

        return DB::transaction(function () use (
            $campaign,
            $executionStrategy,
            $normalizedSettings,
        ): Campaign {
            $locked = Campaign::query()
                ->whereKey($campaign->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertStrategyTransitionIsSafe($locked, $executionStrategy);

            $attributes = [
                'execution_strategy' => $executionStrategy,
                'is_customized' => true,
                'customized_at' => now(),
            ];

            if ($normalizedSettings !== null) {
                $attributes['allocation_settings'] = $normalizedSettings;
            }

            $locked->forceFill($attributes)->save();

            return $locked->refresh();
        }, 3);
    }

    private function assertStrategyTransitionIsSafe(
        Campaign $campaign,
        string $executionStrategy,
    ): void {
        if ($executionStrategy === Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION) {
            if ($campaign->usesAutomaticEnrollment()) {
                throw ValidationException::withMessages([
                    'execution_strategy' => 'Recurring allocation currently requires manual Campaign enrollment.',
                ]);
            }

            if ($this->familyKey($campaign) !== null) {
                throw ValidationException::withMessages([
                    'execution_strategy' => 'Recurring allocation does not yet support Campaign-family arbitration.',
                ]);
            }

            if ($campaign->execution_strategy !== Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION
                && $this->hasOpenSequentialEnrollment($campaign)
            ) {
                throw ValidationException::withMessages([
                    'execution_strategy' => 'End current sequential Campaign enrollments before switching to recurring allocation.',
                ]);
            }

            return;
        }

        if ($campaign->execution_strategy === Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION
            && ($this->hasActiveAllocationEnrollment($campaign)
                || $this->hasOpenAllocationRun($campaign))
        ) {
            throw ValidationException::withMessages([
                'execution_strategy' => 'End current recurring-allocation participation and runs before switching to sequence.',
            ]);
        }
    }

    private function hasOpenSequentialEnrollment(Campaign $campaign): bool
    {
        return CampaignEnrollment::query()
            ->where('campaign_id', $campaign->getKey())
            ->whereNotNull('message_chain_enrollment_id')
            ->whereHas(
                'messageChainEnrollment',
                fn ($query) => $query->whereIn('status', [
                    MessageChainEnrollment::STATUS_ACTIVE,
                    MessageChainEnrollment::STATUS_PAUSED,
                ]),
            )
            ->exists();
    }

    private function hasActiveAllocationEnrollment(Campaign $campaign): bool
    {
        return CampaignAllocationEnrollment::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('status', CampaignAllocationEnrollment::STATUS_ACTIVE)
            ->exists();
    }

    private function hasOpenAllocationRun(Campaign $campaign): bool
    {
        return CampaignAllocationRun::query()
            ->where('campaign_id', $campaign->getKey())
            ->whereIn('status', [
                CampaignAllocationRun::STATUS_SCHEDULED,
                CampaignAllocationRun::STATUS_RUNNING,
            ])
            ->exists();
    }

    private function familyKey(Campaign $campaign): ?string
    {
        if (! is_string($campaign->family_key)) {
            return null;
        }

        $familyKey = trim($campaign->family_key);

        return $familyKey !== '' ? $familyKey : null;
    }
}
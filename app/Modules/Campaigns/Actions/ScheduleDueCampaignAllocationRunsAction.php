<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Jobs\ProcessCampaignAllocationRunJob;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Models\CampaignAllocationRun;
use App\Modules\Campaigns\Services\CampaignAllocationSettingsService;
use App\Modules\Campaigns\Services\CampaignMessageStepResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\User;

final class ScheduleDueCampaignAllocationRunsAction
{
    public function __construct(
        private readonly CampaignAllocationSettingsService $settings,
        private readonly CampaignMessageStepResolver $messageSteps,
    ) {}

    public function handle(Carbon|string|null $at = null): int
    {
        $at = $at ? Carbon::parse($at)->utc() : now()->utc();

        $campaignIds = Campaign::query()
            ->active()
            ->where(
                'execution_strategy',
                Campaign::EXECUTION_STRATEGY_RECURRING_ALLOCATION,
            )
            ->where(function ($query): void {
                $query
                    ->whereNull('family_key')
                    ->orWhere('family_key', '');
            })
            ->whereHas(
                'allocationEnrollments',
                fn ($query) => $query->where(
                    'status',
                    CampaignAllocationEnrollment::STATUS_ACTIVE,
                ),
            )
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $scheduled = 0;

        foreach ($campaignIds as $campaignId) {
            $run = $this->scheduleCampaign($campaignId, $at);

            if (! $run instanceof CampaignAllocationRun) {
                continue;
            }

            ProcessCampaignAllocationRunJob::dispatch(
                runId: (int) $run->getKey(),
            )->afterCommit();

            $scheduled++;
        }

        return $scheduled;
    }

    public function scheduleNow(Campaign $campaign, User $actor, string $requestKey): CampaignAllocationRun
    {
        $requestKey = trim($requestKey);

        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $requestKey)) {
            throw ValidationException::withMessages([
                'request_key' => 'A valid run request is required.',
            ]);
        }

        $run = $this->scheduleCampaign(
            (int) $campaign->getKey(),
            now()->utc(),
            manualRequestKey: $requestKey,
            actor: $actor,
        );

        if ($run->wasRecentlyCreated) {
            ProcessCampaignAllocationRunJob::dispatch(
                runId: (int) $run->getKey(),
            )->afterCommit();
        }

        return $run;
    }

    private function scheduleCampaign(
        int $campaignId,
        Carbon $at,
        ?string $manualRequestKey = null,
        ?User $actor = null,
    ): ?CampaignAllocationRun {
        return DB::transaction(function () use ($campaignId, $at, $manualRequestKey, $actor): ?CampaignAllocationRun {
            $campaign = Campaign::query()
                ->whereKey($campaignId)
                ->lockForUpdate()
                ->first();

            if ($manualRequestKey !== null) {
                $runKey = 'campaign_allocation_manual:'.$campaignId.':'.$manualRequestKey;
                $existingRequest = CampaignAllocationRun::query()
                    ->where('run_key', $runKey)
                    ->first();

                if ($existingRequest instanceof CampaignAllocationRun) {
                    return $existingRequest;
                }
            }

            if (! $campaign instanceof Campaign
                || ! $this->availableCampaign($campaign)
                || ! $this->hasActiveEnrollment($campaign)
            ) {
                if ($manualRequestKey !== null) {
                    throw ValidationException::withMessages([
                        'campaign' => 'An active allocation Campaign with participating leads is required.',
                    ]);
                }

                return null;
            }

            $latest = CampaignAllocationRun::query()
                ->where('campaign_id', $campaign->getKey())
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($latest instanceof CampaignAllocationRun) {
                if (in_array($latest->status, [
                    CampaignAllocationRun::STATUS_RUNNING,
                    CampaignAllocationRun::STATUS_SCHEDULED,
                ], true)) {
                    if ($manualRequestKey !== null) {
                        throw ValidationException::withMessages([
                            'campaign' => 'An allocation run is already scheduled or running.',
                        ]);
                    }

                    return $latest->status === CampaignAllocationRun::STATUS_SCHEDULED
                        ? $latest : null;
                }

                if ($manualRequestKey === null
                    && ! $this->cadenceElapsed($campaign, $latest, $at)
                ) {
                    return null;
                }
            }

            $version = $this->messageSteps->currentVersion($campaign);
            $settings = $this->settings->forCampaign($campaign);
            $runKey = $manualRequestKey !== null
                ? 'campaign_allocation_manual:'.$campaignId.':'.$manualRequestKey
                : implode(':', [
                    'campaign_allocation',
                    (int) $campaign->getKey(),
                    'after',
                    $latest instanceof CampaignAllocationRun
                        ? (int) $latest->getKey()
                        : 0,
                ]);

            return CampaignAllocationRun::query()->firstOrCreate(
                ['run_key' => $runKey],
                [
                    'campaign_id' => $campaign->getKey(),
                    'status' => CampaignAllocationRun::STATUS_SCHEDULED,
                    'scheduled_for' => $at,
                    'started_at' => null,
                    'completed_at' => null,
                    'failed_at' => null,
                    'meta' => [
                        'settings' => $settings,
                        'message_chain_id' => (int) $campaign->message_chain_id,
                        'message_chain_version_id' => (int) $version->getKey(),
                        'trigger' => $manualRequestKey !== null ? 'operator' : 'schedule',
                        'requested_by_user_id' => $actor?->getKey(),
                    ],
                ],
            );
        }, 3);
    }

    private function availableCampaign(Campaign $campaign): bool
    {
        if (! $campaign->isActive()
            || ! $campaign->usesRecurringAllocation()
        ) {
            return false;
        }

        return ! is_string($campaign->family_key)
            || trim($campaign->family_key) === '';
    }

    private function hasActiveEnrollment(Campaign $campaign): bool
    {
        return CampaignAllocationEnrollment::query()
            ->where('campaign_id', $campaign->getKey())
            ->where('status', CampaignAllocationEnrollment::STATUS_ACTIVE)
            ->exists();
    }

    private function cadenceElapsed(
        Campaign $campaign,
        CampaignAllocationRun $latest,
        Carbon $at,
    ): bool {
        if ($latest->status === CampaignAllocationRun::STATUS_CANCELLED) {
            return true;
        }

        $anchor = $latest->completed_at
            ?? $latest->failed_at
            ?? $latest->started_at
            ?? $latest->scheduled_for;

        if ($anchor === null) {
            return true;
        }

        $days = $this->settings->forCampaign($campaign)['run_every_days'];

        return Carbon::parse($anchor)
            ->utc()
            ->addDays($days)
            ->lte($at);
    }
}
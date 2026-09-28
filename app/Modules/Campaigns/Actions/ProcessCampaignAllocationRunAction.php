<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationAssignment;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Models\CampaignAllocationRun;
use App\Modules\Campaigns\Services\CampaignAllocationCandidateSelector;
use App\Modules\Campaigns\Services\CampaignAllocationMessagePlanner;
use App\Modules\Campaigns\Services\CampaignAllocationSettingsService;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ProcessCampaignAllocationRunAction
{
    public function __construct(
        private readonly CampaignAllocationSettingsService $settings,
        private readonly CampaignAllocationCandidateSelector $candidates,
        private readonly CampaignAllocationMessagePlanner $messagePlanner,
    ) {}

    public function handle(
        CampaignAllocationRun|int $run,
    ): CampaignAllocationRun {
        $runId = $run instanceof CampaignAllocationRun
            ? (int) $run->getKey()
            : $run;

        $runtime = $this->claim($runId);

        if ($runtime['terminal']) {
            return $runtime['run'];
        }

        /** @var CampaignAllocationRun $claimedRun */
        $claimedRun = $runtime['run'];

        try {
            $campaign = $claimedRun->campaign;

            if (! $campaign instanceof Campaign) {
                throw new RuntimeException(
                    "Campaign allocation run [{$runId}] has no Campaign.",
                );
            }

            $version = $this->pinnedVersion($claimedRun);
            $orderedSteps = $version->steps
                ->filter(
                    fn (MessageChainStep $step): bool =>
                        (bool) $step->is_active,
                )
                ->sort(function (MessageChainStep $left, MessageChainStep $right): int {
                    return ((int) $left->sort_order <=> (int) $right->sort_order)
                        ?: ((int) $left->getKey() <=> (int) $right->getKey());
                })
                ->values();

            $settings = $this->runSettings($claimedRun);
            $this->resumeOutstandingAssignments($claimedRun);

            foreach ($orderedSteps as $step) {
                $this->allocateStep(
                    run: $claimedRun,
                    campaign: $campaign,
                    version: $version,
                    step: $step,
                    orderedSteps: $orderedSteps,
                    settings: $settings,
                );
            }

            return $this->complete($claimedRun);
        } catch (Throwable $exception) {
            $this->fail($claimedRun, $exception);

            throw $exception;
        }
    }

    /**
     * @return array{run: CampaignAllocationRun, terminal: bool}
     */
    private function claim(int $runId): array
    {
        return DB::transaction(function () use ($runId): array {
            $run = CampaignAllocationRun::query()
                ->with('campaign')
                ->whereKey($runId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($run->status, [
                CampaignAllocationRun::STATUS_COMPLETED,
                CampaignAllocationRun::STATUS_CANCELLED,
            ], true)) {
                return ['run' => $run, 'terminal' => true];
            }

            $campaign = $run->campaign;

            if (! $campaign instanceof Campaign
                || ! $this->campaignAvailable($campaign)
                || ! $this->hasActiveEnrollment($campaign)
            ) {
                $run->forceFill([
                    'status' => CampaignAllocationRun::STATUS_CANCELLED,
                    'completed_at' => now(),
                    'failed_at' => null,
                    'meta' => array_replace_recursive(
                        is_array($run->meta) ? $run->meta : [],
                        [
                            'runtime' => [
                                'cancelled_reason' => 'campaign_no_longer_available',
                                'cancelled_at' => now()->toISOString(),
                            ],
                        ],
                    ),
                ])->save();

                return ['run' => $run->refresh(), 'terminal' => true];
            }

            $now = now();

            $run->forceFill([
                'status' => CampaignAllocationRun::STATUS_RUNNING,
                'started_at' => $run->started_at ?? $now,
                'failed_at' => null,
                'meta' => array_replace_recursive(
                    is_array($run->meta) ? $run->meta : [],
                    [
                        'runtime' => [
                            'last_attempt_started_at' => $now->toISOString(),
                        ],
                    ],
                ),
            ])->save();

            return ['run' => $run->refresh()->load('campaign'), 'terminal' => false];
        }, 3);
    }

    /**
     * @param array{
     *     run_every_days: int,
     *     allocation_size_per_message: int,
     *     recipient_cooldown_days: int
     * } $settings
     * @param Collection<int, MessageChainStep> $orderedSteps
     */
    private function allocateStep(
        CampaignAllocationRun $run,
        Campaign $campaign,
        MessageChainVersion $version,
        MessageChainStep $step,
        Collection $orderedSteps,
        array $settings,
    ): void {
        if ($step->variant_strategy
            !== MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE
        ) {
            throw new RuntimeException(sprintf(
                'Recurring allocation message [%s] must use the first_available variant strategy.',
                (string) $step->key,
            ));
        }

        $quota = max(1, (int) $settings['allocation_size_per_message']);
        $existingCount = CampaignAllocationAssignment::query()
            ->where(
                'campaign_allocation_run_id',
                $run->getKey(),
            )
            ->where('message_step_key', (string) $step->key)
            ->count();

        $remaining = max(0, $quota - $existingCount);

        if ($remaining === 0) {
            return;
        }

        $examinedContactIds = [];

        while ($remaining > 0) {
            $enrollments = $this->candidates->select(
                campaign: $campaign,
                runId: (int) $run->getKey(),
                step: $step,
                orderedSteps: $orderedSteps,
                cooldownDays: (int) $settings['recipient_cooldown_days'],
                limit: min(500, max(100, $remaining * 4)),
                excludeContactIds: $examinedContactIds,
                at: $run->started_at ?? now(),
            );

            if ($enrollments->isEmpty()) {
                break;
            }

            foreach ($enrollments as $enrollment) {
                if (! $enrollment instanceof CampaignAllocationEnrollment
                    || ! $enrollment->contact instanceof Contact
                ) {
                    continue;
                }

                $examinedContactIds[] = (int) $enrollment->contact_id;

                $variant = $this->messagePlanner->selectVariant(
                    campaign: $campaign,
                    enrollment: $enrollment,
                    step: $step,
                );

                if (! $variant instanceof MessageChainStepVariant) {
                    continue;
                }

                $assignment = $this->createAssignment(
                    run: $run,
                    campaign: $campaign,
                    version: $version,
                    enrollment: $enrollment,
                    step: $step,
                    variant: $variant,
                );

                if (! $assignment instanceof CampaignAllocationAssignment
                    || (int) $assignment->campaign_allocation_run_id
                        !== (int) $run->getKey()
                ) {
                    continue;
                }

                $this->messagePlanner->scheduleAssignment($assignment);
                $remaining--;

                if ($remaining === 0) {
                    break;
                }
            }
        }
    }

    private function createAssignment(
        CampaignAllocationRun $run,
        Campaign $campaign,
        MessageChainVersion $version,
        CampaignAllocationEnrollment $enrollment,
        MessageChainStep $step,
        MessageChainStepVariant $variant,
    ): ?CampaignAllocationAssignment {
        return DB::transaction(function () use (
            $run,
            $campaign,
            $version,
            $enrollment,
            $step,
            $variant,
        ): ?CampaignAllocationAssignment {
            CampaignAllocationRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existingForRun = CampaignAllocationAssignment::query()
                ->where(
                    'campaign_allocation_run_id',
                    $run->getKey(),
                )
                ->where('contact_id', $enrollment->contact_id)
                ->lockForUpdate()
                ->first();

            if ($existingForRun instanceof CampaignAllocationAssignment) {
                return null;
            }

            $existingForStep = CampaignAllocationAssignment::query()
                ->where('campaign_id', $campaign->getKey())
                ->where('contact_id', $enrollment->contact_id)
                ->where('message_step_key', (string) $step->key)
                ->lockForUpdate()
                ->first();

            if ($existingForStep instanceof CampaignAllocationAssignment) {
                return $existingForStep;
            }

            return CampaignAllocationAssignment::query()->create([
                'campaign_id' => $campaign->getKey(),
                'contact_id' => $enrollment->contact_id,
                'campaign_allocation_run_id' => $run->getKey(),
                'campaign_allocation_enrollment_id' => $enrollment->getKey(),
                'message_chain_version_id' => $version->getKey(),
                'message_step_key' => (string) $step->key,
                'scheduled_message_id' => null,
                'assigned_at' => now(),
                'sent_at' => null,
                'meta' => [
                    'selection' => [
                        'message_chain_step_id' => (int) $step->getKey(),
                        'message_chain_step_variant_id' => (int) $variant->getKey(),
                        'variant_key' => (string) $variant->key,
                        'channel' => (string) $variant->channel,
                        'purpose' => (string) $variant->purpose,
                        'scope' => (string) $variant->scope,
                        'message_type' => (string) $variant->message_type,
                    ],
                ],
            ]);
        }, 3);
    }

    private function resumeOutstandingAssignments(
        CampaignAllocationRun $run,
    ): void {
        CampaignAllocationAssignment::query()
            ->where(
                'campaign_allocation_run_id',
                $run->getKey(),
            )
            ->whereNull('scheduled_message_id')
            ->orderBy('id')
            ->eachById(
                function (CampaignAllocationAssignment $assignment): void {
                    $this->messagePlanner->scheduleAssignment($assignment);
                },
                100,
            );
    }

    private function pinnedVersion(
        CampaignAllocationRun $run,
    ): MessageChainVersion {
        $versionId = data_get(
            $run->meta,
            'message_chain_version_id',
        );
        $chainId = data_get(
            $run->meta,
            'message_chain_id',
        );

        if (! is_numeric($versionId) || ! is_numeric($chainId)) {
            throw new RuntimeException(sprintf(
                'Campaign allocation run [%d] has no immutable MessageChain snapshot.',
                (int) $run->getKey(),
            ));
        }

        $version = MessageChainVersion::query()
            ->with('steps.variants.messageTemplateVersion')
            ->whereKey((int) $versionId)
            ->first();

        if (! $version instanceof MessageChainVersion
            || ! $version->isPublished()
            || (int) $version->message_chain_id !== (int) $chainId
        ) {
            throw new RuntimeException(sprintf(
                'Campaign allocation run [%d] references an invalid immutable MessageChainVersion.',
                (int) $run->getKey(),
            ));
        }

        return $version;
    }

    /**
     * @return array{
     *     run_every_days: int,
     *     allocation_size_per_message: int,
     *     recipient_cooldown_days: int
     * }
     */
    private function runSettings(CampaignAllocationRun $run): array
    {
        $settings = data_get($run->meta, 'settings');

        if (! is_array($settings)) {
            $campaign = $run->campaign;

            if (! $campaign instanceof Campaign) {
                throw new RuntimeException(
                    "Campaign allocation run [{$run->getKey()}] has no Campaign.",
                );
            }

            return $this->settings->forCampaign($campaign);
        }

        return $this->settings->normalize($settings);
    }

    private function complete(
        CampaignAllocationRun $run,
    ): CampaignAllocationRun {
        return DB::transaction(function () use ($run): CampaignAllocationRun {
            $locked = CampaignAllocationRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === CampaignAllocationRun::STATUS_CANCELLED) {
                return $locked;
            }

            $assignments = CampaignAllocationAssignment::query()
                ->where(
                    'campaign_allocation_run_id',
                    $locked->getKey(),
                )
                ->get(['message_step_key', 'scheduled_message_id']);

            $perMessage = $assignments
                ->groupBy('message_step_key')
                ->map(
                    fn (Collection $items): array => [
                        'assigned' => $items->count(),
                        'scheduled' => $items
                            ->whereNotNull('scheduled_message_id')
                            ->count(),
                    ],
                )
                ->all();

            $now = now();
            $locked->forceFill([
                'status' => CampaignAllocationRun::STATUS_COMPLETED,
                'completed_at' => $now,
                'failed_at' => null,
                'meta' => array_replace_recursive(
                    is_array($locked->meta) ? $locked->meta : [],
                    [
                        'runtime' => [
                            'completed_at' => $now->toISOString(),
                            'assignment_count' => $assignments->count(),
                            'scheduled_message_count' => $assignments
                                ->whereNotNull('scheduled_message_id')
                                ->count(),
                            'per_message' => $perMessage,
                        ],
                    ],
                ),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    private function fail(
        CampaignAllocationRun $run,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($run, $exception): void {
            $locked = CampaignAllocationRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof CampaignAllocationRun
                || $locked->status === CampaignAllocationRun::STATUS_CANCELLED
                || $locked->status === CampaignAllocationRun::STATUS_COMPLETED
            ) {
                return;
            }

            $now = now();

            $locked->forceFill([
                'status' => CampaignAllocationRun::STATUS_FAILED,
                'failed_at' => $now,
                'meta' => array_replace_recursive(
                    is_array($locked->meta) ? $locked->meta : [],
                    [
                        'runtime' => [
                            'last_failure' => [
                                'exception' => $exception::class,
                                'message' => $exception->getMessage(),
                                'failed_at' => $now->toISOString(),
                            ],
                        ],
                    ],
                ),
            ])->save();
        }, 3);
    }

    private function campaignAvailable(Campaign $campaign): bool
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
}
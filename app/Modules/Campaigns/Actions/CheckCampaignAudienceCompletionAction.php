<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Contracts\CampaignAudienceCompletionNotifier;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Services\CampaignEligibilityAuthoringService;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CheckCampaignAudienceCompletionAction
{
    private const SETTLE_MINUTES = 15;

    public function __construct(
        private readonly CampaignEligibilityAuthoringService $eligibility,
        private readonly CampaignAudienceCompletionNotifier $notifier,
    ) {}

    public function handle(?Carbon $at = null): void
    {
        $at ??= now();

        Campaign::query()
            ->active()
            ->where('enrollment_mode', Campaign::ENROLLMENT_MODE_AUTOMATIC)
            ->orderBy('id')
            ->chunkById(100, function ($campaigns) use ($at): void {
                foreach ($campaigns as $campaign) {
                    $this->check((int) $campaign->getKey(), $at);
                }
            });
    }

    private function check(int $id, Carbon $at): void
    {
        DB::transaction(function () use ($id, $at): void {
            $campaign = Campaign::query()->whereKey($id)->lockForUpdate()->first();

            if (! $campaign?->isActive()
                || ! $campaign->usesAutomaticEnrollment()
                || ! $campaign->hasEligibilityCriteria()
            ) {
                return;
            }

            $latest = fn (): Builder => CampaignEnrollment::query()
                ->where('campaign_enrollments.campaign_id', $campaign->getKey())
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('campaign_enrollments as later')
                    ->whereColumn('later.contact_id', 'campaign_enrollments.contact_id')
                    ->where('later.campaign_id', $campaign->getKey())
                    ->whereColumn('later.id', '>', 'campaign_enrollments.id'));

            $completed = $latest()
                ->whereHas('messageChainEnrollment', fn (Builder $query) => $query
                    ->where('status', MessageChainEnrollment::STATUS_COMPLETED));
            $completedCount = (clone $completed)->count();
            $lastCompletedId = (int) (clone $completed)->max('campaign_enrollments.id');

            $open = $latest()->whereHas('messageChainEnrollment', fn (Builder $query) => $query
                ->whereIn('status', [
                    MessageChainEnrollment::STATUS_ACTIVE,
                    MessageChainEnrollment::STATUS_PAUSED,
                ]))->exists();

            $unstarted = $this->eligibility->matchingQuery($campaign)
                ->whereNotExists(fn ($query) => $query
                    ->selectRaw('1')
                    ->from('campaign_enrollments')
                    ->whereColumn('campaign_enrollments.contact_id', 'contacts.id')
                    ->where('campaign_enrollments.campaign_id', $campaign->getKey()))
                ->exists();

            $meta = is_array($campaign->meta) ? $campaign->meta : [];
            $state = is_array($meta['audience_completion'] ?? null)
                ? $meta['audience_completion'] : [];

            if ($completedCount === 0 || $open || $unstarted) {
                if (($state['candidate_since'] ?? null) !== null || ($state['notified_at'] ?? null) !== null) {
                    $meta['audience_completion'] = [
                        'cycle' => (int) ($state['cycle'] ?? 0),
                        'candidate_since' => null,
                        'notified_at' => null,
                    ];
                    $campaign->forceFill(['meta' => $meta])->save();
                }

                return;
            }

            if (($state['notified_at'] ?? null) !== null) {
                if ($completedCount <= (int) ($state['completed_count'] ?? 0)
                    && $lastCompletedId <= (int) ($state['last_completed_enrollment_id'] ?? 0)
                ) {
                    return;
                }

                $state['candidate_since'] = null;
                $state['notified_at'] = null;
            }

            $candidateSince = is_string($state['candidate_since'] ?? null)
                ? Carbon::parse($state['candidate_since']) : null;

            if ($candidateSince === null) {
                $meta['audience_completion'] = [
                    'cycle' => (int) ($state['cycle'] ?? 0),
                    'candidate_since' => $at->toISOString(),
                    'notified_at' => null,
                ];
                $campaign->forceFill(['meta' => $meta])->save();

                return;
            }

            if ($candidateSince->greaterThan($at->copy()->subMinutes(self::SETTLE_MINUTES))) {
                return;
            }

            $cycle = (int) ($state['cycle'] ?? 0) + 1;

            if (! $this->notifier->notify($campaign, $completedCount, $cycle)) {
                return;
            }

            $meta['audience_completion'] = [
                'cycle' => $cycle,
                'candidate_since' => $candidateSince->toISOString(),
                'notified_at' => $at->toISOString(),
                'completed_count' => $completedCount,
                'last_completed_enrollment_id' => $lastCompletedId,
            ];
            $campaign->forceFill(['meta' => $meta])->save();
        }, 3);
    }
}
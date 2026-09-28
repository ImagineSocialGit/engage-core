<?php

namespace App\Modules\Campaigns\Services;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationAssignment;
use App\Modules\Campaigns\Models\CampaignAllocationRun;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Messaging\Models\MessageChainVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class CampaignAllocationHistoryService
{
    public function __construct(
        private readonly ContactVisibility $visibility,
    ) {}

    /** @return Collection<int, CampaignAllocationRun> */
    public function recent(Campaign $campaign, int $limit = 3): Collection
    {
        return $this->runsQuery($campaign)
            ->limit($limit)
            ->get();
    }

    /** @return LengthAwarePaginator<CampaignAllocationRun> */
    public function runs(Campaign $campaign): LengthAwarePaginator
    {
        return $this->runsQuery($campaign)->paginate(15)->withQueryString();
    }

    public function run(Campaign $campaign, int $runId): CampaignAllocationRun
    {
        return $this->runsQuery($campaign)->whereKey($runId)->firstOrFail();
    }

    /** @return Collection<int, array{key: string, name: string, assigned: int, planned: int, sent: int}> */
    public function messages(CampaignAllocationRun $run): Collection
    {
        $versionId = data_get($run->meta, 'message_chain_version_id');
        $version = is_numeric($versionId)
            ? MessageChainVersion::query()->with('steps')->find((int) $versionId)
            : null;
        $counts = CampaignAllocationAssignment::query()
            ->where('campaign_allocation_run_id', $run->getKey())
            ->select('message_step_key')
            ->selectRaw('COUNT(*) AS assigned_count')
            ->selectRaw('SUM(CASE WHEN scheduled_message_id IS NOT NULL THEN 1 ELSE 0 END) AS planned_count')
            ->selectRaw('SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) AS sent_count')
            ->groupBy('message_step_key')
            ->get()
            ->keyBy('message_step_key');

        $steps = $version
            ? $version->steps
                ->filter(fn ($step): bool => (bool) $step->is_active)
                ->sortBy('sort_order')
                ->values()
            : collect();

        $messages = $steps->map(function ($step) use ($counts): array {
            $row = $counts->get($step->key);

            return [
                'key' => (string) $step->key,
                'name' => (string) $step->name,
                'assigned' => (int) ($row?->assigned_count ?? 0),
                'planned' => (int) ($row?->planned_count ?? 0),
                'sent' => (int) ($row?->sent_count ?? 0),
            ];
        });

        $knownKeys = $steps->pluck('key')->all();

        foreach ($counts as $key => $row) {
            if (in_array($key, $knownKeys, true)) {
                continue;
            }

            $messages->push([
                'key' => (string) $key,
                'name' => (string) $key,
                'assigned' => (int) $row->assigned_count,
                'planned' => (int) $row->planned_count,
                'sent' => (int) $row->sent_count,
            ]);
        }

        return $messages;
    }

    /** @return LengthAwarePaginator<CampaignAllocationAssignment> */
    public function assignments(
        CampaignAllocationRun $run,
        User $user,
        ?string $messageStepKey = null,
    ): LengthAwarePaginator {
        return CampaignAllocationAssignment::query()
            ->where('campaign_allocation_run_id', $run->getKey())
            ->when($messageStepKey !== null, fn (Builder $query) => $query
                ->where('message_step_key', $messageStepKey))
            ->whereHas('contact', fn (Builder $query) => $this->visibility->apply($query, $user))
            ->with(['contact', 'scheduledMessage'])
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();
    }

    /** @return Builder<CampaignAllocationRun> */
    private function runsQuery(Campaign $campaign): Builder
    {
        return CampaignAllocationRun::query()
            ->where('campaign_id', $campaign->getKey())
            ->withCount([
                'assignments',
                'assignments as planned_messages_count' => fn (Builder $query) => $query
                    ->whereNotNull('scheduled_message_id'),
                'assignments as sent_messages_count' => fn (Builder $query) => $query
                    ->whereNotNull('sent_at'),
            ])
            ->orderByDesc('scheduled_for')
            ->orderByDesc('id');
    }
}
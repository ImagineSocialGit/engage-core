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
            ->get()
            ->map(fn (CampaignAllocationRun $run) => $this->presentRun($run));
    }

    /** @return LengthAwarePaginator<CampaignAllocationRun> */
    public function runs(Campaign $campaign): LengthAwarePaginator
    {
        $runs = $this->runsQuery($campaign)
            ->paginate(15)
            ->withQueryString();

        $runs->setCollection(
            $runs->getCollection()
                ->map(fn (CampaignAllocationRun $run) => $this->presentRun($run)),
        );

        return $runs;
    }

    public function run(Campaign $campaign, int $runId): CampaignAllocationRun
    {
        return $this->presentRun(
            $this->runsQuery($campaign)->whereKey($runId)->firstOrFail(),
        );
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
        $assignments = CampaignAllocationAssignment::query()
            ->where('campaign_allocation_run_id', $run->getKey())
            ->when($messageStepKey !== null, fn (Builder $query) => $query
                ->where('message_step_key', $messageStepKey))
            ->whereHas('contact', fn (Builder $query) => $this->visibility->apply($query, $user))
            ->with(['contact', 'scheduledMessage'])
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        $assignments->setCollection(
            $assignments->getCollection()->map(
                fn (CampaignAllocationAssignment $assignment) =>
                    $this->presentAssignment($assignment),
            ),
        );

        return $assignments;
    }


    private function presentRun(
        CampaignAllocationRun $run,
    ): CampaignAllocationRun {
        $run->setAttribute(
            'scheduled_for_label',
            $this->date($run->scheduled_for),
        );
        $run->setAttribute(
            'started_at_label',
            $this->date($run->started_at),
        );
        $run->setAttribute(
            'completed_at_label',
            $this->date($run->completed_at),
        );

        return $run;
    }

    private function presentAssignment(
        CampaignAllocationAssignment $assignment,
    ): CampaignAllocationAssignment {
        $assignment->setAttribute(
            'sent_at_label',
            $this->date($assignment->sent_at),
        );
        $assignment->setAttribute(
            'scheduled_message_send_at_label',
            $this->date($assignment->scheduledMessage?->send_at),
        );

        return $assignment;
    }

    private function date(mixed $date): ?string
    {
        return $date?->timezone(config('client.timezone', config('app.timezone', 'UTC')))
            ->format('M j, Y g:i A');
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
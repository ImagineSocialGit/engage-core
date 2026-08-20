<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Models\ScheduledMessageCtaEngagement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PruneScheduledMessageCtaEngagementsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $retentionDays = max(
            1,
            (int) config('messaging.cta_tracking.retention_days', 180),
        );
        $batchSize = max(
            100,
            min(5000, (int) config('messaging.cta_tracking.prune_batch_size', 1000)),
        );
        $remaining = max(
            $batchSize,
            (int) config('messaging.cta_tracking.prune_max_rows_per_run', 10000),
        );
        $cutoff = now()->subDays($retentionDays);

        while ($remaining > 0) {
            $limit = min($batchSize, $remaining);

            $ids = ScheduledMessageCtaEngagement::query()
                ->whereHas(
                    'scheduledMessage',
                    fn ($query) => $query->where('send_at', '<', $cutoff),
                )
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id');

            if ($ids->isEmpty()) {
                return;
            }

            ScheduledMessageCtaEngagement::query()
                ->whereKey($ids->all())
                ->delete();

            $remaining -= $ids->count();

            if ($ids->count() < $limit) {
                return;
            }
        }
    }
}
<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Jobs\ContinueFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Services\FlowRouteProgressMetaCanonicalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecoverStalledFlowRouteContinuationsAction
{
    public function __construct(
        private readonly FlowRouteProgressMetaCanonicalizer $progressMetaCanonicalizer,
    ) {}

    /**
     * Recover only explicitly scheduled, overdue continuation handoffs.
     * Redis does not hold the authority for either eligibility or retry pacing.
     *
     * @return array{checked: int, dispatched: int, skipped: int, failed: int}
     */
    public function handle(int $limit = 25, int $staleSeconds = 300): array
    {
        $now = CarbonImmutable::now('UTC');
        $cutoff = $now->subSeconds(max(60, $staleSeconds));
        $limit = max(1, min(100, $limit));

        $ids = ContactFlowRouteProgress::query()
            ->active()
            ->where('meta->immediate_execution_continuation->scheduled_at', '<=', $cutoff->toISOString())
            ->where('meta->immediate_execution_continuation->status', 'scheduled')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $summary = ['checked' => 0, 'dispatched' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($ids as $id) {
            $summary['checked']++;

            $claim = DB::transaction(function () use ($id, $now, $cutoff): ?array {
                $progress = ContactFlowRouteProgress::query()
                    ->lockForUpdate()
                    ->find($id);

                if (! $progress instanceof ContactFlowRouteProgress || ! $progress->isActive()) {
                    return null;
                }

                $meta = $progress->meta ?? [];
                $continuation = $meta['immediate_execution_continuation'] ?? null;

                if (! is_array($continuation) || ($continuation['status'] ?? null) !== 'scheduled') {
                    return null;
                }

                $scheduledAt = $continuation['scheduled_at'] ?? null;

                if (! is_string($scheduledAt) || trim($scheduledAt) === '') {
                    return null;
                }

                try {
                    if (CarbonImmutable::parse($scheduledAt)->utc()->greaterThan($cutoff)) {
                        return null;
                    }
                } catch (Throwable) {
                    return null;
                }

                // Reuse the bounded persisted marker as a database-backed
                // redispatch claim. Competing sweepers see the new timestamp
                // under the row lock and cannot immediately dispatch again.
                $meta['immediate_execution_continuation']['scheduled_at'] = $now->toISOString();

                $progress->forceFill([
                    'meta' => $this->progressMetaCanonicalizer->forPersistence($meta),
                ])->save();

                return [
                    'sequence' => (int) ($continuation['sequence'] ?? 0),
                    'scheduled_at' => $meta['immediate_execution_continuation']['scheduled_at'],
                ];
            });

            if ($claim === null) {
                $summary['skipped']++;
                continue;
            }

            try {
                // Bypass the Redis uniqueness gate only for a MySQL-claimed
                // recovery. A stale uniqueness key could otherwise suppress
                // the replacement without producing a job in Redis.
                Bus::dispatch(new ContinueFlowRouteProgressJob(
                    (int) $id,
                    $claim['sequence'],
                    $claim['scheduled_at'],
                ));
                $summary['dispatched']++;
            } catch (Throwable $exception) {
                report($exception);
                // Dispatch may have been accepted before an exception. Keep
                // the claim and retry after the stale window, not instantly.
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
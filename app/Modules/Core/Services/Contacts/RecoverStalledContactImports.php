<?php

namespace App\Modules\Core\Services\Contacts;

use App\Modules\Core\Jobs\FinalizeContactImportBatchJob;
use App\Modules\Core\Jobs\ProcessContactImportBatchChunkJob;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportRun;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Repair lost Redis handoffs using import checkpoints stored in MySQL.
 *
 * A stalled run is redispatched, not replayed in this scheduler process. The
 * normal chunk/finalizer locks and durable CSV cursor remain authoritative.
 * Claims use compare-and-set updates so parallel schedulers claim at most once
 * per recovery interval, independently of Redis uniqueness locks.
 */
final class RecoverStalledContactImports
{
    public function recover(): int
    {
        $staleSeconds = max(180, (int) config('contact_imports.recovery.stale_after_seconds', 300));
        $retrySeconds = max(60, (int) config('contact_imports.recovery.redispatch_after_seconds', 300));
        $limit = max(1, min(50, (int) config('contact_imports.recovery.max_per_run', 25)));

        $now = now();
        $staleBefore = $now->copy()->subSeconds($staleSeconds);
        $claimBefore = $now->copy()->subSeconds($retrySeconds);

        $candidates = $this->eligible($staleBefore, $claimBefore)
            ->orderBy('contact_import_runs.updated_at')
            ->orderBy('contact_import_runs.id')
            ->limit($limit)
            ->get([
                'contact_import_runs.id',
                'contact_import_runs.contact_import_batch_id',
                'contact_import_runs.status',
            ]);

        $dispatched = 0;

        foreach ($candidates as $candidate) {
            // This is the durable handoff claim. The checkpoint must remain
            // stalled and the batch must still be active when claiming.
            $claimed = $this->eligible($staleBefore, $claimBefore)
                ->where('contact_import_runs.id', $candidate->id)
                ->where('contact_import_runs.status', $candidate->status)
                ->update(['recovery_dispatched_at' => $now]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                $batchId = (int) $candidate->contact_import_batch_id;

                $job = $candidate->status === ContactImportRun::STATUS_FINALIZING
                    ? new FinalizeContactImportBatchJob($batchId)
                    : new ProcessContactImportBatchChunkJob($batchId);

                // PendingDispatch enforces ShouldBeUniqueUntilProcessing and
                // silently drops redispatches while an orphaned unique lock
                // survives. This is an explicit repair of a stalled job: the
                // durable MySQL claim bounds retries, and the worker's
                // checkpoint/finalization locks protect duplicate execution.
                // Bus::dispatch bypasses PendingDispatch's uniqueness gate
                // while retaining the job's configured queue/connection.
                Bus::dispatch($job);

                $dispatched++;
            } catch (Throwable $exception) {
                // Preserve the claim: if Redis is still unavailable, a later
                // scheduler invocation will retry after the bounded interval.
                // Never mark an import failed solely due to dispatch failure.
                report($exception);
            }
        }

        return $dispatched;
    }

    private function eligible(\DateTimeInterface $staleBefore, \DateTimeInterface $claimBefore): Builder
    {
        return DB::table('contact_import_runs')
            ->whereIn('contact_import_runs.status', [
                ContactImportRun::STATUS_PENDING,
                ContactImportRun::STATUS_PROCESSING,
                ContactImportRun::STATUS_FINALIZING,
            ])
            ->where('contact_import_runs.updated_at', '<=', $staleBefore)
            ->where(function (Builder $query) use ($claimBefore): void {
                $query->whereNull('contact_import_runs.recovery_dispatched_at')
                    ->orWhere('contact_import_runs.recovery_dispatched_at', '<=', $claimBefore);
            })
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('contact_import_batches')
                    ->whereColumn(
                        'contact_import_batches.id',
                        'contact_import_runs.contact_import_batch_id',
                    )
                    ->whereIn('contact_import_batches.status', [
                        ContactImportBatch::STATUS_PENDING,
                        ContactImportBatch::STATUS_PROCESSING,
                    ]);
            });
    }
}
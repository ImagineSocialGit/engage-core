<?php

namespace App\Modules\Core\Services\Contacts;

use App\Modules\Core\Jobs\EmitContactImportAutomationEventsJob;
use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reconstruct lost import-event continuation jobs from durable batch cursors.
 *
 * This reconciler never emits events itself. The worker serializes chunks
 * through the batch row lock and commits outbox rows with the cursor.
 */
final class RecoverStalledContactImportAutomationEvents
{
    public function recover(): int
    {
        $staleSeconds = max(180, (int) config('contact_imports.automation_events_recovery.stale_after_seconds', 300));
        $retrySeconds = max(60, (int) config('contact_imports.automation_events_recovery.redispatch_after_seconds', 300));
        $limit = max(1, min(50, (int) config('contact_imports.automation_events_recovery.max_per_run', 25)));

        $now = now();
        $staleBefore = $now->copy()->subSeconds($staleSeconds);
        $claimBefore = $now->copy()->subSeconds($retrySeconds);

        $candidates = $this->eligible($staleBefore, $claimBefore)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;

        foreach ($candidates as $batchId) {
            $claimed = $this->eligible($staleBefore, $claimBefore)
                ->where('id', $batchId)
                ->update([
                    'automation_events_recovery_dispatched_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($claimed !== 1) {
                continue;
            }

            try {
                // Ordinary PendingDispatch may suppress a replacement when
                // an old ShouldBeUniqueUntilProcessing Redis lock remains.
                // The database CAS claim bounds *recovery* dispatch instead.
                Bus::dispatch(new EmitContactImportAutomationEventsJob((int) $batchId));
                $dispatched++;
            } catch (Throwable $exception) {
                // Keep the claim for retry after the configured interval.
                // Redis unavailability must not mark a completed import failed.
                report($exception);
            }
        }

        return $dispatched;
    }

    private function eligible(\DateTimeInterface $staleBefore, \DateTimeInterface $claimBefore): Builder
    {
        return DB::table('contact_import_batches')
            ->where('status', ContactImportBatch::STATUS_COMPLETED)
            ->whereNotNull('automation_events_cursor')
            ->whereNull('automation_events_completed_at')
            ->where('updated_at', '<=', $staleBefore)
            ->where(function (Builder $query) use ($claimBefore): void {
                $query->whereNull('automation_events_recovery_dispatched_at')
                    ->orWhere('automation_events_recovery_dispatched_at', '<=', $claimBefore);
            });
    }
}
<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Services\FlowRouteProgressMetaCanonicalizer;
use Illuminate\Support\Facades\DB;

final class EnsureFlowRouteInlineRecoveryMarkerAction
{
    private const META_KEY = 'immediate_execution_continuation';

    public function __construct(
        private readonly FlowRouteProgressMetaCanonicalizer $canonicalizer,
    ) {}

    /**
     * Seed a durable recovery marker in the SAME transaction that creates or
     * reactivates progress. Sequence zero represents inline execution; no job
     * is dispatched unless the inline process is lost or exhausts its budget.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function forRunnableProgress(array $meta, ?int $pointId): array
    {
        if (isset($meta[self::META_KEY])) {
            return $this->canonicalizer->forPersistence($meta);
        }

        $meta[self::META_KEY] = [
            'status' => 'scheduled',
            'sequence' => 0,
            'scheduled_at' => now()->toISOString(),
            'flow_route_point_id' => $pointId,
        ];

        return $this->canonicalizer->forPersistence($meta);
    }

    /**
     * Also cover direct callers of the inline executor, and progress created
     * before the transactional entry-point markers were introduced.
     */
    public function handle(ContactFlowRouteProgress $progress): void
    {
        DB::transaction(function () use ($progress): void {
            $locked = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->find($progress->getKey());

            if (! $locked instanceof ContactFlowRouteProgress
                || ! $locked->isActive()
            ) {
                return;
            }

            $meta = $locked->meta ?? [];

            if (isset($meta[self::META_KEY])) {
                return;
            }

            $locked->forceFill([
                'meta' => $this->forRunnableProgress(
                    $meta,
                    $locked->current_flow_route_point_id !== null
                        ? (int) $locked->current_flow_route_point_id
                        : null,
                ),
            ])->save();
        });

        $progress->refresh();
    }
}
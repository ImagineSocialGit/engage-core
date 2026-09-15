<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Facades\DB;

class RemoveRejectedWebinarRegistrationAction
{
    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessages,
    ) {}

    public function handle(
        WebinarRegistration $registration,
        ?int $operatorId = null,
    ): bool {
        $removed = DB::transaction(function () use ($registration, $operatorId): bool {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->find($registration->getKey());

            if (! $locked instanceof WebinarRegistration) {
                return false;
            }

            if ($locked->status === 'cancelled' || $locked->cancelled_at !== null) {
                return true;
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $finalization = is_array(
                $meta[WebinarRegistrationFinalizationResult::META_KEY] ?? null,
            )
                ? $meta[WebinarRegistrationFinalizationResult::META_KEY]
                : [];
            $providerSync = is_array($meta['provider_sync'] ?? null)
                ? $meta['provider_sync']
                : [];

            if (
                ($finalization['status'] ?? null) !== 'failed'
                || ($providerSync['status'] ?? null) !== 'permanent_failure'
            ) {
                return false;
            }

            $removedAt = now();
            $removedAtIso = $removedAt->toISOString();
            $priorFailureReason = is_string($finalization['failure_reason'] ?? null)
                ? $finalization['failure_reason']
                : null;
            $existingCancellation = is_array($meta['cancellation'] ?? null)
                ? $meta['cancellation']
                : [];

            $meta['registration_recovery'] = [
                'status' => 'removed',
                'decision' => 'remove_registration',
                'removed_at' => $removedAtIso,
                'removed_by' => $operatorId,
                'prior_finalization_status' => 'failed',
                'prior_finalization_failure_reason' => $priorFailureReason,
                'prior_provider_sync_status' => 'permanent_failure',
                'provider_error_code' => $providerSync['provider_error_code'] ?? null,
                'provider_error_message' => $providerSync['provider_error_message'] ?? null,
            ];
            $meta['cancellation'] = array_replace($existingCancellation, [
                'source' => 'crm_registration_recovery',
                'cancelled_at' => $removedAtIso,
                'resolved_from_registration_id' => (int) $locked->getKey(),
                'canonical_registration_id' => (int) $locked->getKey(),
                'traversed_registration_ids' => [(int) $locked->getKey()],
            ]);
            $meta[WebinarRegistrationFinalizationResult::META_KEY] = array_replace(
                $finalization,
                [
                    'status' => 'completed',
                    'completed_at' => $removedAtIso,
                    'processing_started_at' => null,
                    'next_retry_at' => null,
                    'failure_reason' => null,
                    'completion_reason' => 'operator_removed_registration',
                    'last_error_class' => null,
                    'last_error_code' => null,
                    'last_state_changed_at' => $removedAtIso,
                ],
            );

            $locked->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => $removedAt,
                'meta' => $meta,
            ])->save();

            $this->skipScheduledMessages->forContext(
                context: $locked,
                reason: 'Webinar registration removed during recovery.',
            );

            return true;
        });

        return $removed;
    }
}
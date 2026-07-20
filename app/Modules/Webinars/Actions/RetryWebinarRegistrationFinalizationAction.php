<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Facades\DB;

class RetryWebinarRegistrationFinalizationAction
{
    public function __construct(
        private readonly QueueWebinarRegistrationFinalizationAction $queueFinalization,
    ) {}

    public function handle(
        WebinarRegistration $registration,
        ?int $operatorId = null,
    ): WebinarRegistrationFinalizationResult {
        $reset = DB::transaction(function () use ($registration, $operatorId): WebinarRegistrationFinalizationResult|true {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->find($registration->getKey());

            if (! $locked instanceof WebinarRegistration) {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_NOT_REQUIRED,
                    registrationId: (int) $registration->getKey(),
                    reason: 'registration_missing',
                );
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $state = $this->state($meta);
            $status = (string) ($state['status'] ?? '');
            $providerSync = is_array($meta['provider_sync'] ?? null)
                ? $meta['provider_sync']
                : [];
            $providerStatus = (string) ($providerSync['status'] ?? '');

            if ($status === 'reconciliation_required'
                || $providerStatus === 'reconciliation_required'
            ) {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_RECONCILIATION_REQUIRED,
                    registrationId: (int) $locked->getKey(),
                    reason: 'provider_reconciliation_required',
                );
            }

            if ($status === 'completed') {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_ALREADY_COMPLETED,
                    registrationId: (int) $locked->getKey(),
                );
            }

            if ($status !== 'failed') {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_IN_PROGRESS,
                    registrationId: (int) $locked->getKey(),
                    reason: 'finalization_not_failed',
                );
            }

            $retriedAt = now()->toISOString();
            $priorReason = $this->nullableString($state['failure_reason'] ?? null);

            $meta[WebinarRegistrationFinalizationResult::META_KEY] = array_replace(
                $state,
                [
                    'status' => 'pending',
                    'failed_at' => null,
                    'queued_at' => null,
                    'processing_started_at' => null,
                    'next_retry_at' => null,
                    'failure_reason' => null,
                    'last_error_class' => null,
                    'last_error_code' => null,
                    'operator_retry_count' => ((int) ($state['operator_retry_count'] ?? 0)) + 1,
                    'last_operator_retry_at' => $retriedAt,
                    'last_operator_retry_by' => $operatorId,
                    'last_operator_retry_reason' => $priorReason,
                    'last_state_changed_at' => $retriedAt,
                ],
            );

            if (in_array($providerStatus, [
                'retryable_failure',
                'permanent_failure',
                'claimed',
            ], true)) {
                $meta['provider_sync'] = array_replace($providerSync, [
                    'status' => 'pending',
                    'claim_started_at' => null,
                    'submission_started_at' => null,
                    'failed_at' => null,
                    'reconciliation_required_at' => null,
                    'failure_reason' => null,
                    'last_error_class' => null,
                    'last_error_code' => null,
                    'operator_retry_authorized_at' => $retriedAt,
                    'operator_retry_authorized_by' => $operatorId,
                ]);
            }

            $locked->forceFill(['meta' => $meta])->save();

            return true;
        });

        if ($reset instanceof WebinarRegistrationFinalizationResult) {
            return $reset;
        }

        return $this->queueFinalization->handle((int) $registration->getKey());
    }

    /** @param array<string, mixed> $meta */
    private function state(array $meta): array
    {
        $state = $meta[WebinarRegistrationFinalizationResult::META_KEY] ?? null;

        return is_array($state) ? $state : [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
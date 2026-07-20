<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Jobs\SyncWebinarRegistrationToProviderJob;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class QueueWebinarRegistrationFinalizationAction
{
    public function handle(
        int|WebinarRegistration $registration,
    ): WebinarRegistrationFinalizationResult {
        $registrationId = $registration instanceof WebinarRegistration
            ? (int) $registration->getKey()
            : $registration;

        $claim = $this->claimQueueDispatch($registrationId);

        if ($claim instanceof WebinarRegistrationFinalizationResult) {
            return $claim;
        }

        try {
            SyncWebinarRegistrationToProviderJob::dispatch($registrationId);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordQueueDispatchFailure(
                registrationId: $registrationId,
                queueToken: $claim['queue_token'],
                exception: $exception,
            );

            $fresh = WebinarRegistration::query()->find($registrationId);

            return $fresh instanceof WebinarRegistration
                ? $this->resultFromState($fresh)
                : new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_NOT_REQUIRED,
                    registrationId: $registrationId,
                    reason: 'registration_missing',
                );
        }

        $fresh = WebinarRegistration::query()->find($registrationId);

        return $fresh instanceof WebinarRegistration
            ? $this->resultFromState($fresh)
            : new WebinarRegistrationFinalizationResult(
                status: WebinarRegistrationFinalizationResult::STATUS_NOT_REQUIRED,
                registrationId: $registrationId,
                reason: 'registration_missing',
            );
    }

    /** @return array{queue_token: string}|WebinarRegistrationFinalizationResult */
    private function claimQueueDispatch(
        int $registrationId,
    ): array|WebinarRegistrationFinalizationResult {
        return DB::transaction(function () use ($registrationId): array|WebinarRegistrationFinalizationResult {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->find($registrationId);

            if (! $locked instanceof WebinarRegistration) {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_NOT_REQUIRED,
                    registrationId: $registrationId,
                    reason: 'registration_missing',
                );
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $state = $this->state($meta);

            if ($state === []) {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_NOT_REQUIRED,
                    registrationId: $registrationId,
                    reason: 'finalization_not_staged',
                );
            }

            $status = (string) ($state['status'] ?? 'pending');

            if ($status === 'completed') {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_ALREADY_COMPLETED,
                    registrationId: $registrationId,
                );
            }

            if ($status === 'failed') {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_FAILED,
                    registrationId: $registrationId,
                    reason: $this->nullableString($state['failure_reason'] ?? null),
                );
            }

            if ($status === 'reconciliation_required') {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_RECONCILIATION_REQUIRED,
                    registrationId: $registrationId,
                    reason: $this->nullableString($state['failure_reason'] ?? null),
                );
            }

            if ($this->retryIsDeferred($state)) {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_PENDING,
                    registrationId: $registrationId,
                    reason: 'retry_deferred',
                );
            }

            if (
                $status === 'queued'
                && $this->isFreshTimestamp(
                    $state['queued_at'] ?? null,
                    $this->queueStaleAfterSeconds(),
                )
            ) {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_IN_PROGRESS,
                    registrationId: $registrationId,
                    reason: 'already_queued',
                );
            }

            if (
                $status === 'processing'
                && $this->isFreshTimestamp(
                    $state['last_attempted_at'] ?? null,
                    $this->processingStaleAfterSeconds(),
                )
            ) {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_IN_PROGRESS,
                    registrationId: $registrationId,
                    reason: 'processing',
                );
            }

            $queueToken = (string) Str::uuid();
            $queuedAt = now()->toISOString();

            $meta[WebinarRegistrationFinalizationResult::META_KEY] = array_replace(
                $state,
                [
                    'status' => 'queued',
                    'queue_token' => $queueToken,
                    'queue_dispatch_attempts' => ((int) ($state['queue_dispatch_attempts'] ?? 0)) + 1,
                    'first_queued_at' => $state['first_queued_at'] ?? $queuedAt,
                    'last_queue_attempted_at' => $queuedAt,
                    'queued_at' => $queuedAt,
                    'next_retry_at' => null,
                    'queue_failed_at' => null,
                    'queue_error_class' => null,
                    'queue_error_code' => null,
                    'last_state_changed_at' => $queuedAt,
                ],
            );

            $locked->forceFill(['meta' => $meta])->save();

            return ['queue_token' => $queueToken];
        });
    }

    private function recordQueueDispatchFailure(
        int $registrationId,
        string $queueToken,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($registrationId, $queueToken, $exception): void {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->find($registrationId);

            if (! $locked instanceof WebinarRegistration) {
                return;
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $state = $this->state($meta);

            if (
                ($state['status'] ?? null) !== 'queued'
                || ($state['queue_token'] ?? null) !== $queueToken
            ) {
                return;
            }

            $failedAt = now();

            $meta[WebinarRegistrationFinalizationResult::META_KEY] = array_replace(
                $state,
                [
                    'status' => 'pending',
                    'queued_at' => null,
                    'queue_failed_at' => $failedAt->toISOString(),
                    'next_retry_at' => $failedAt
                        ->copy()
                        ->addSeconds($this->queueFailureRetrySeconds())
                        ->toISOString(),
                    'failure_reason' => 'queue_dispatch_failed',
                    'queue_error_class' => $exception::class,
                    'queue_error_code' => (string) $exception->getCode(),
                    'last_state_changed_at' => $failedAt->toISOString(),
                ],
            );

            $locked->forceFill(['meta' => $meta])->save();
        });
    }

    private function resultFromState(
        WebinarRegistration $registration,
    ): WebinarRegistrationFinalizationResult {
        $state = $this->state(is_array($registration->meta) ? $registration->meta : []);
        $status = (string) ($state['status'] ?? 'pending');
        $reason = $this->nullableString($state['failure_reason'] ?? null);

        return match ($status) {
            'completed' => new WebinarRegistrationFinalizationResult(
                WebinarRegistrationFinalizationResult::STATUS_COMPLETED,
                (int) $registration->getKey(),
                $reason,
            ),
            'failed' => new WebinarRegistrationFinalizationResult(
                WebinarRegistrationFinalizationResult::STATUS_FAILED,
                (int) $registration->getKey(),
                $reason,
            ),
            'reconciliation_required' => new WebinarRegistrationFinalizationResult(
                WebinarRegistrationFinalizationResult::STATUS_RECONCILIATION_REQUIRED,
                (int) $registration->getKey(),
                $reason,
            ),
            'queued', 'processing' => new WebinarRegistrationFinalizationResult(
                WebinarRegistrationFinalizationResult::STATUS_IN_PROGRESS,
                (int) $registration->getKey(),
                $reason,
            ),
            default => new WebinarRegistrationFinalizationResult(
                WebinarRegistrationFinalizationResult::STATUS_PENDING,
                (int) $registration->getKey(),
                $reason,
            ),
        };
    }

    /** @param array<string, mixed> $meta */
    private function state(array $meta): array
    {
        $state = $meta[WebinarRegistrationFinalizationResult::META_KEY] ?? null;

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private function retryIsDeferred(array $state): bool
    {
        $nextRetryAt = $state['next_retry_at'] ?? null;

        if (! is_string($nextRetryAt) || trim($nextRetryAt) === '') {
            return false;
        }

        try {
            return Carbon::parse($nextRetryAt)->isFuture();
        } catch (Throwable) {
            return false;
        }
    }

    private function isFreshTimestamp(
        mixed $value,
        int $staleAfterSeconds,
    ): bool {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        try {
            return Carbon::parse($value)->greaterThan(
                now()->subSeconds($staleAfterSeconds),
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function queueStaleAfterSeconds(): int
    {
        return max(
            30,
            (int) config(
                'webinars.registration.finalization.queue_stale_after_seconds',
                300,
            ),
        );
    }

    private function processingStaleAfterSeconds(): int
    {
        return max(
            60,
            (int) config(
                'webinars.registration.finalization.processing_stale_after_seconds',
                600,
            ),
        );
    }

    private function queueFailureRetrySeconds(): int
    {
        return max(
            1,
            (int) config(
                'webinars.registration.finalization.queue_failure_retry_seconds',
                60,
            ),
        );
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
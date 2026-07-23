<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\WebinarProviderSyncResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarStateCanonicalizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncWebinarRegistrationToProviderAction
{
    public function __construct(
        private readonly AddRegistrantToWebinarProviderAction $addRegistrantToProvider,
        private readonly ?WebinarStateCanonicalizer $stateCanonicalizer = null,
    ) {}

    public function handle(WebinarRegistration $registration): WebinarProviderSyncResult
    {
        $registration->loadMissing('webinar');
        $webinar = $registration->webinar;

        if (! $webinar || blank($webinar->providerKey()) || blank($webinar->external_id)) {
            return new WebinarProviderSyncResult(
                status: WebinarProviderSyncResult::STATUS_NOT_REQUIRED,
                provider: $webinar?->providerKey(),
            );
        }

        $claim = $this->claimAttempt($registration);

        if ($claim !== true) {
            return $claim;
        }

        try {
            if (! $this->markSubmissionStarted($registration)) {
                return new WebinarProviderSyncResult(
                    status: WebinarProviderSyncResult::STATUS_IN_PROGRESS,
                    provider: $webinar->providerKey(),
                    reason: 'provider_sync_claim_changed',
                );
            }

            $providerRegistration = $this->addRegistrantToProvider->handle(
                $webinar,
                $registration->fresh(['contact', 'webinar']) ?? $registration,
            );
        } catch (Throwable $exception) {
            $classification = $this->classifyFailure($exception);
            $this->recordFailure(
                registration: $registration,
                status: $classification['status'],
                reason: $classification['reason'],
                exception: $exception,
            );
            report($exception);

            return new WebinarProviderSyncResult(
                status: $classification['result_status'],
                provider: $webinar->providerKey(),
                reason: $classification['reason'],
            );
        }

        DB::transaction(function () use ($registration, $providerRegistration): void {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $sync = is_array($meta['provider_sync'] ?? null)
                ? $meta['provider_sync']
                : [];

            $meta['provider'] = $this->canonicalizer()->registrationProvider(
                $providerRegistration->toMeta(),
            );
            $meta['provider_sync'] = $this->canonicalizer()->providerSync(
                array_replace($sync, [
                    'status' => 'succeeded',
                    'provider' => $providerRegistration->provider,
                    'succeeded_at' => now()->toISOString(),
                    'claim_started_at' => null,
                    'submission_started_at' => null,
                    'failed_at' => null,
                    'failure_reason' => null,
                    'last_error_class' => null,
                    'last_error_code' => null,
                ]),
            );

            $locked->forceFill(['meta' => $meta])->save();
        });

        return new WebinarProviderSyncResult(
            status: WebinarProviderSyncResult::STATUS_SUCCEEDED,
            provider: $providerRegistration->provider,
        );
    }

    private function claimAttempt(
        WebinarRegistration $registration,
    ): true|WebinarProviderSyncResult {
        return DB::transaction(function () use ($registration): true|WebinarProviderSyncResult {
            $locked = WebinarRegistration::query()
                ->with('webinar')
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $sync = is_array($meta['provider_sync'] ?? null)
                ? $meta['provider_sync']
                : [];
            $status = (string) ($sync['status'] ?? '');
            $provider = is_string($sync['provider'] ?? null)
                ? $sync['provider']
                : $locked->webinar?->providerKey();

            if ($status === 'succeeded') {
                return new WebinarProviderSyncResult(
                    status: WebinarProviderSyncResult::STATUS_ALREADY_SUCCEEDED,
                    provider: $provider,
                );
            }

            if ($status === 'reconciliation_required') {
                return new WebinarProviderSyncResult(
                    status: WebinarProviderSyncResult::STATUS_RECONCILIATION_REQUIRED,
                    provider: $provider,
                    reason: $this->nullableString($sync['failure_reason'] ?? null),
                );
            }

            if ($status === 'permanent_failure') {
                return new WebinarProviderSyncResult(
                    status: WebinarProviderSyncResult::STATUS_PERMANENT_FAILURE,
                    provider: $provider,
                    reason: $this->nullableString($sync['failure_reason'] ?? null),
                );
            }

            if ($status === 'submitting' || $status === 'syncing') {
                if ($this->submissionIsFresh($sync)) {
                    return new WebinarProviderSyncResult(
                        status: WebinarProviderSyncResult::STATUS_IN_PROGRESS,
                        provider: $provider,
                        reason: 'provider_submission_in_progress',
                    );
                }

                $recordedAt = now()->toISOString();
                $meta['provider_sync'] = $this->canonicalizer()->providerSync(
                    array_replace($sync, [
                        'status' => 'reconciliation_required',
                        'reconciliation_required_at' => $recordedAt,
                        'failed_at' => $recordedAt,
                        'failure_reason' => 'stale_provider_submission_outcome_unknown',
                        'last_error_class' => null,
                        'last_error_code' => null,
                    ]),
                );
                $locked->forceFill(['meta' => $meta])->save();

                return new WebinarProviderSyncResult(
                    status: WebinarProviderSyncResult::STATUS_RECONCILIATION_REQUIRED,
                    provider: $provider,
                    reason: 'stale_provider_submission_outcome_unknown',
                );
            }

            if (
                $status === 'claimed'
                && $this->isFreshTimestamp(
                    $sync['claim_started_at'] ?? $sync['last_attempted_at'] ?? null,
                )
            ) {
                return new WebinarProviderSyncResult(
                    status: WebinarProviderSyncResult::STATUS_IN_PROGRESS,
                    provider: $provider,
                    reason: 'provider_sync_claimed',
                );
            }

            $attemptedAt = now()->toISOString();
            $meta['provider_sync'] = $this->canonicalizer()->providerSync(
                array_replace($sync, [
                    'status' => 'claimed',
                    'provider' => $locked->webinar?->providerKey(),
                    'attempts' => ((int) ($sync['attempts'] ?? 0)) + 1,
                    'first_attempted_at' => $sync['first_attempted_at'] ?? $attemptedAt,
                    'last_attempted_at' => $attemptedAt,
                    'claim_started_at' => $attemptedAt,
                    'submission_started_at' => null,
                    'failed_at' => null,
                    'failure_reason' => null,
                    'last_error_class' => null,
                    'last_error_code' => null,
                ]),
            );

            $locked->forceFill(['meta' => $meta])->save();

            return true;
        });
    }

    private function markSubmissionStarted(
        WebinarRegistration $registration,
    ): bool {
        return DB::transaction(function () use ($registration): bool {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $sync = is_array($meta['provider_sync'] ?? null)
                ? $meta['provider_sync']
                : [];

            if (($sync['status'] ?? null) !== 'claimed') {
                return false;
            }

            $meta['provider_sync'] = $this->canonicalizer()->providerSync(
                array_replace($sync, [
                    'status' => 'submitting',
                    'submission_started_at' => now()->toISOString(),
                ]),
            );

            $locked->forceFill(['meta' => $meta])->save();

            return true;
        });
    }

    /**
     * @return array{status: string, result_status: string, reason: string}
     */
    private function classifyFailure(Throwable $exception): array
    {
        if ($exception instanceof RequestException) {
            $statusCode = $exception->response->status();

            if (in_array($statusCode, [425, 429], true)) {
                return [
                    'status' => 'retryable_failure',
                    'result_status' => WebinarProviderSyncResult::STATUS_RETRYABLE_FAILURE,
                    'reason' => 'provider_temporarily_rejected_submission',
                ];
            }

            if (
                $statusCode >= 400
                && $statusCode < 500
                && ! in_array($statusCode, [408, 409], true)
            ) {
                return [
                    'status' => 'permanent_failure',
                    'result_status' => WebinarProviderSyncResult::STATUS_PERMANENT_FAILURE,
                    'reason' => 'provider_rejected_registration',
                ];
            }

            return [
                'status' => 'reconciliation_required',
                'result_status' => WebinarProviderSyncResult::STATUS_RECONCILIATION_REQUIRED,
                'reason' => 'provider_submission_outcome_unknown',
            ];
        }

        if ($exception instanceof ConnectionException) {
            return [
                'status' => 'reconciliation_required',
                'result_status' => WebinarProviderSyncResult::STATUS_RECONCILIATION_REQUIRED,
                'reason' => 'provider_submission_connection_lost',
            ];
        }

        // Once submission begins, an unexpected exception may occur after the
        // provider accepted the registrant. Automatic retry would risk a
        // duplicate remote registration, so the outcome requires review.
        return [
            'status' => 'reconciliation_required',
            'result_status' => WebinarProviderSyncResult::STATUS_RECONCILIATION_REQUIRED,
            'reason' => 'provider_submission_outcome_unknown',
        ];
    }

    private function recordFailure(
        WebinarRegistration $registration,
        string $status,
        string $reason,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($registration, $status, $reason, $exception): void {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $sync = is_array($meta['provider_sync'] ?? null)
                ? $meta['provider_sync']
                : [];
            $failedAt = now()->toISOString();

            $meta['provider_sync'] = $this->canonicalizer()->providerSync(
                array_replace($sync, [
                    'status' => $status,
                    'failed_at' => $failedAt,
                    'reconciliation_required_at' => $status === 'reconciliation_required'
                        ? $failedAt
                        : null,
                    'failure_reason' => $reason,
                    'last_error_class' => $exception::class,
                    'last_error_code' => (string) $exception->getCode(),
                ]),
            );

            $locked->forceFill(['meta' => $meta])->save();
        });
    }

    /** @param array<string, mixed> $sync */
    private function submissionIsFresh(array $sync): bool
    {
        return $this->isFreshTimestamp(
            $sync['submission_started_at']
                ?? $sync['last_attempted_at']
                ?? null,
        );
    }

    private function isFreshTimestamp(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        try {
            return Carbon::parse($value)->greaterThan(
                now()->subSeconds($this->providerClaimStaleAfterSeconds()),
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function providerClaimStaleAfterSeconds(): int
    {
        return max(
            60,
            (int) config(
                'webinars.registration.finalization.provider_claim_stale_after_seconds',
                600,
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

    private function canonicalizer(): WebinarStateCanonicalizer
    {
        return $this->stateCanonicalizer
            ?? app(WebinarStateCanonicalizer::class);
    }
}
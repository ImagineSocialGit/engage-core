<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Webinars\Data\WebinarRegistrationConsentTransition;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Data\WebinarRegistrationResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class FinalizeWebinarRegistrationAction
{
    public function __construct(
        private readonly SyncWebinarRegistrationToProviderAction $syncToProvider,
        private readonly DispatchWebinarRegistrationMessagesAction $dispatchRegistrationMessages,
    ) {}

    public function handle(
        WebinarRegistration|WebinarRegistrationResult $subject,
    ): ?WebinarRegistrationFinalizationResult {
        $registration = $subject instanceof WebinarRegistrationResult
            ? $subject->registration
            : $subject;
        $claim = $this->claimFinalization($registration);

        if ($claim instanceof WebinarRegistrationFinalizationResult) {
            return $claim;
        }

        $registration = $registration->fresh([
            'contact',
            'webinar',
            'webinar.webinarSeries',
            'replacementOfRegistration',
            'replacementRegistration',
        ]) ?? $registration;

        if (
            $claim['mode'] === 'replacement_reprovisioning'
            && ! $registration->replacementOfRegistration instanceof WebinarRegistration
        ) {
            return $this->markFailed(
                registration: $registration,
                reason: 'replacement_source_registration_missing',
            );
        }

        $consentGrants = $claim['mode'] === 'replacement_reprovisioning'
            ? []
            : $this->rehydrateConsentGrants(
                $claim['consent_transitions'],
            );

        if (count($consentGrants) !== count($claim['consent_transitions'])) {
            return $this->markFailed(
                registration: $registration,
                reason: 'consent_transition_missing',
            );
        }

        if ($claim['mode'] === 'consent_acknowledgements') {
            try {
                $this->dispatchConsentAcknowledgements(
                    registration: $registration,
                    consentGrants: $consentGrants,
                );
            } catch (Throwable $exception) {
                report($exception);

                return $this->markPending(
                    registration: $registration,
                    reason: 'consent_acknowledgement_planning_failed',
                    exception: $exception,
                );
            }

            return $this->markCompleted(
                registration: $registration,
                reason: 'consent_acknowledgements_planned',
            );
        }

        try {
            $syncResult = $this->syncToProvider->handle($registration);
        } catch (Throwable $exception) {
            report($exception);

            return $this->markPending(
                registration: $registration,
                reason: 'provider_sync_exception',
                exception: $exception,
            );
        }

        if ($syncResult->requiresReconciliation()) {
            return $this->markReconciliationRequired(
                registration: $registration,
                reason: $syncResult->reason ?? 'provider_submission_outcome_unknown',
            );
        }

        if ($syncResult->permanentlyFailed()) {
            return $this->markFailed(
                registration: $registration,
                reason: $syncResult->reason ?? 'provider_sync_permanent_failure',
            );
        }

        if ($syncResult->shouldRetry()) {
            return $this->markPending(
                registration: $registration,
                reason: $syncResult->reason ?? 'provider_sync_pending',
            );
        }

        if (! $syncResult->readyForRegistrationMessages()) {
            return $this->markPending(
                registration: $registration,
                reason: 'provider_sync_not_ready',
            );
        }

        $registration = $registration->fresh([
            'contact',
            'webinar',
            'webinar.webinarSeries',
            'replacementOfRegistration',
            'replacementRegistration',
        ]) ?? $registration;

        if (
            $claim['mode'] !== 'replacement_reprovisioning'
            && $registration->replacementRegistration instanceof WebinarRegistration
        ) {
            return $this->markCompleted(
                registration: $registration,
                reason: 'occurrence_replaced_before_registration_messages',
                providerSyncStatus: $syncResult->status,
            );
        }

        try {
            $this->dispatchRegistrationMessages->handle(
                $registration->fresh([
                    'contact',
                    'webinar',
                    'webinar.webinarSeries',
                    'replacementOfRegistration',
                ]) ?? $registration,
                $claim['mode'] === 'replacement_reprovisioning'
                    ? ['reminders']
                    : null,
                $claim['mode'] === 'replacement_reprovisioning'
                    ? []
                    : $consentGrants,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->markPending(
                registration: $registration,
                reason: 'registration_message_planning_failed',
                exception: $exception,
            );
        }

        return $this->markCompleted(
            registration: $registration,
            reason: $claim['mode'] === 'replacement_reprovisioning'
                ? 'replacement_reminders_planned'
                : 'registration_messages_planned',
            providerSyncStatus: $syncResult->status,
        );
    }

    /**
     * @return array{mode: string, consent_transitions: array<int, array<string, mixed>>}|WebinarRegistrationFinalizationResult
     */
    private function claimFinalization(
        WebinarRegistration $registration,
    ): array|WebinarRegistrationFinalizationResult {
        return DB::transaction(function () use ($registration): array|WebinarRegistrationFinalizationResult {
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

            if ($state === []) {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_NOT_REQUIRED,
                    registrationId: (int) $locked->getKey(),
                    reason: 'finalization_not_staged',
                );
            }

            $status = (string) ($state['status'] ?? 'pending');

            if ($status === 'completed') {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_ALREADY_COMPLETED,
                    registrationId: (int) $locked->getKey(),
                );
            }

            if ($status === 'failed') {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_FAILED,
                    registrationId: (int) $locked->getKey(),
                    reason: $this->nullableString($state['failure_reason'] ?? null),
                );
            }

            if ($status === 'reconciliation_required') {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_RECONCILIATION_REQUIRED,
                    registrationId: (int) $locked->getKey(),
                    reason: $this->nullableString($state['failure_reason'] ?? null),
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
                    registrationId: (int) $locked->getKey(),
                    reason: 'processing',
                );
            }

            $attemptedAt = now()->toISOString();
            $mode = $this->mode($state);
            $transitions = is_array($state['consent_transitions'] ?? null)
                ? array_values(array_filter(
                    $state['consent_transitions'],
                    fn (mixed $transition): bool => is_array($transition),
                ))
                : [];

            $meta[WebinarRegistrationFinalizationResult::META_KEY] = array_replace(
                $state,
                [
                    'status' => 'processing',
                    'mode' => $mode,
                    'attempts' => ((int) ($state['attempts'] ?? 0)) + 1,
                    'first_attempted_at' => $state['first_attempted_at'] ?? $attemptedAt,
                    'last_attempted_at' => $attemptedAt,
                    'processing_started_at' => $attemptedAt,
                    'next_retry_at' => null,
                    'failure_reason' => null,
                    'last_error_class' => null,
                    'last_error_code' => null,
                    'last_state_changed_at' => $attemptedAt,
                ],
            );

            $locked->forceFill(['meta' => $meta])->save();

            return [
                'mode' => $mode,
                'consent_transitions' => $transitions,
            ];
        });
    }

    /**
     * @param array<int, MessageConsentGrantResult> $consentGrants
     */
    private function dispatchConsentAcknowledgements(
        WebinarRegistration $registration,
        array $consentGrants,
    ): void {
        if (! $registration->contact instanceof Contact) {
            throw new \RuntimeException(
                'Webinar registration contact is unavailable for consent acknowledgement planning.',
            );
        }

        $this->dispatchRegistrationMessages->handle(
            registration: $registration,
            contextKeys: null,
            consentGrants: $consentGrants,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $transitions
     * @return array<int, MessageConsentGrantResult>
     */
    private function rehydrateConsentGrants(array $transitions): array
    {
        $grants = [];

        foreach ($transitions as $transitionData) {
            $grant = WebinarRegistrationConsentTransition::fromArray(
                $transitionData,
            )->toGrant();

            if ($grant instanceof MessageConsentGrantResult) {
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    private function markCompleted(
        WebinarRegistration $registration,
        string $reason,
        ?string $providerSyncStatus = null,
    ): WebinarRegistrationFinalizationResult {
        $this->updateProcessingState($registration, [
            'status' => 'completed',
            'completed_at' => now()->toISOString(),
            'processing_started_at' => null,
            'next_retry_at' => null,
            'failure_reason' => null,
            'completion_reason' => $reason,
            'provider_sync_status' => $providerSyncStatus,
            'last_error_class' => null,
            'last_error_code' => null,
        ]);

        return new WebinarRegistrationFinalizationResult(
            status: WebinarRegistrationFinalizationResult::STATUS_COMPLETED,
            registrationId: (int) $registration->getKey(),
            reason: $reason,
        );
    }

    private function markPending(
        WebinarRegistration $registration,
        string $reason,
        ?Throwable $exception = null,
    ): WebinarRegistrationFinalizationResult {
        $retryAt = now()->addSeconds($this->retryDelaySeconds());

        $this->updateProcessingState($registration, [
            'status' => 'pending',
            'processing_started_at' => null,
            'next_retry_at' => $retryAt->toISOString(),
            'failure_reason' => $reason,
            'last_error_class' => $exception ? $exception::class : null,
            'last_error_code' => $exception ? (string) $exception->getCode() : null,
        ]);

        return new WebinarRegistrationFinalizationResult(
            status: WebinarRegistrationFinalizationResult::STATUS_PENDING,
            registrationId: (int) $registration->getKey(),
            reason: $reason,
        );
    }

    private function markFailed(
        WebinarRegistration $registration,
        string $reason,
        ?Throwable $exception = null,
    ): WebinarRegistrationFinalizationResult {
        $this->updateProcessingState($registration, [
            'status' => 'failed',
            'processing_started_at' => null,
            'failed_at' => now()->toISOString(),
            'next_retry_at' => null,
            'failure_reason' => $reason,
            'last_error_class' => $exception ? $exception::class : null,
            'last_error_code' => $exception ? (string) $exception->getCode() : null,
        ]);

        return new WebinarRegistrationFinalizationResult(
            status: WebinarRegistrationFinalizationResult::STATUS_FAILED,
            registrationId: (int) $registration->getKey(),
            reason: $reason,
        );
    }

    private function markReconciliationRequired(
        WebinarRegistration $registration,
        string $reason,
    ): WebinarRegistrationFinalizationResult {
        $this->updateProcessingState($registration, [
            'status' => 'reconciliation_required',
            'processing_started_at' => null,
            'reconciliation_required_at' => now()->toISOString(),
            'next_retry_at' => null,
            'failure_reason' => $reason,
        ]);

        return new WebinarRegistrationFinalizationResult(
            status: WebinarRegistrationFinalizationResult::STATUS_RECONCILIATION_REQUIRED,
            registrationId: (int) $registration->getKey(),
            reason: $reason,
        );
    }

    /** @param array<string, mixed> $changes */
    private function updateProcessingState(
        WebinarRegistration $registration,
        array $changes,
    ): void {
        DB::transaction(function () use ($registration, $changes): void {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->find($registration->getKey());

            if (! $locked instanceof WebinarRegistration) {
                return;
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $state = $this->state($meta);

            if (($state['status'] ?? null) !== 'processing') {
                return;
            }

            $meta[WebinarRegistrationFinalizationResult::META_KEY] = array_replace(
                $state,
                $changes,
                ['last_state_changed_at' => now()->toISOString()],
            );

            $locked->forceFill(['meta' => $meta])->save();
        });
    }

    /** @param array<string, mixed> $meta */
    private function state(array $meta): array
    {
        $state = $meta[WebinarRegistrationFinalizationResult::META_KEY] ?? null;

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    private function mode(array $state): string
    {
        return match ($state['mode'] ?? null) {
            'consent_acknowledgements' => 'consent_acknowledgements',
            'replacement_reprovisioning' => 'replacement_reprovisioning',
            default => 'initial_registration',
        };
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

    private function retryDelaySeconds(): int
    {
        return max(
            1,
            (int) config(
                'webinars.registration.finalization.retry_delay_seconds',
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
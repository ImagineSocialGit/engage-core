<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\ProviderRegistrationData;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Facades\DB;

class ResolveWebinarRegistrationReconciliationAction
{
    public const DECISION_PROVIDER_EXISTS = 'provider_exists';
    public const DECISION_PROVIDER_ABSENT = 'provider_absent';

    public function __construct(
        private readonly QueueWebinarRegistrationFinalizationAction $queueFinalization,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function handle(
        WebinarRegistration $registration,
        array $data,
        ?int $operatorId = null,
    ): WebinarRegistrationFinalizationResult {
        $reset = DB::transaction(function () use ($registration, $data, $operatorId): WebinarRegistrationFinalizationResult|true {
            $locked = WebinarRegistration::query()
                ->with('webinar')
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
            $providerSync = is_array($meta['provider_sync'] ?? null)
                ? $meta['provider_sync']
                : [];

            if (($state['status'] ?? null) === 'completed') {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_ALREADY_COMPLETED,
                    registrationId: (int) $locked->getKey(),
                );
            }

            if (($state['status'] ?? null) !== 'reconciliation_required'
                || ($providerSync['status'] ?? null) !== 'reconciliation_required'
            ) {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_IN_PROGRESS,
                    registrationId: (int) $locked->getKey(),
                    reason: 'provider_reconciliation_not_required',
                );
            }

            $decision = (string) ($data['decision'] ?? '');
            $resolvedAt = now()->toISOString();
            $provider = $this->providerName($providerSync, $locked);
            $notes = $this->nullableString($data['notes'] ?? null);
            $priorReason = $this->nullableString($providerSync['failure_reason'] ?? null)
                ?? $this->nullableString($state['failure_reason'] ?? null);

            $resolution = [
                'decision' => $decision,
                'resolved_at' => $resolvedAt,
                'resolved_by' => $operatorId,
                'notes' => $notes,
                'prior_failure_reason' => $priorReason,
            ];

            if ($decision === self::DECISION_PROVIDER_EXISTS) {
                $registrantId = $this->nullableString(
                    $data['provider_registrant_id'] ?? null,
                );
                $joinUrl = $this->nullableString(
                    $data['provider_join_url'] ?? null,
                );

                if ($registrantId === null || $joinUrl === null) {
                    return new WebinarRegistrationFinalizationResult(
                        status: WebinarRegistrationFinalizationResult::STATUS_RECONCILIATION_REQUIRED,
                        registrationId: (int) $locked->getKey(),
                        reason: 'provider_registration_identity_required',
                    );
                }

                $providerRegistration = new ProviderRegistrationData(
                    provider: $provider,
                    registrantId: $registrantId,
                    joinUrl: $joinUrl,
                    raw: [
                        'reconciled_manually' => true,
                        'reconciled_at' => $resolvedAt,
                        'reconciled_by' => $operatorId,
                    ],
                );

                $meta['provider'] = $providerRegistration->toMeta();
                $meta['provider_sync'] = array_replace($providerSync, [
                    'status' => 'succeeded',
                    'provider' => $provider,
                    'succeeded_at' => $resolvedAt,
                    'claim_started_at' => null,
                    'submission_started_at' => null,
                    'failed_at' => null,
                    'reconciliation_required_at' => null,
                    'failure_reason' => null,
                    'last_error_class' => null,
                    'last_error_code' => null,
                    'reconciliation_resolution' => $resolution,
                ]);
            } elseif ($decision === self::DECISION_PROVIDER_ABSENT) {
                unset($meta['provider']);

                $meta['provider_sync'] = array_replace($providerSync, [
                    'status' => 'pending',
                    'provider' => $provider,
                    'claim_started_at' => null,
                    'submission_started_at' => null,
                    'failed_at' => null,
                    'reconciliation_required_at' => null,
                    'failure_reason' => null,
                    'last_error_class' => null,
                    'last_error_code' => null,
                    'resubmission_authorized_at' => $resolvedAt,
                    'resubmission_authorized_by' => $operatorId,
                    'reconciliation_resolution' => $resolution,
                ]);
            } else {
                return new WebinarRegistrationFinalizationResult(
                    status: WebinarRegistrationFinalizationResult::STATUS_RECONCILIATION_REQUIRED,
                    registrationId: (int) $locked->getKey(),
                    reason: 'invalid_reconciliation_decision',
                );
            }

            $meta[WebinarRegistrationFinalizationResult::META_KEY] = array_replace(
                $state,
                [
                    'status' => 'pending',
                    'queued_at' => null,
                    'processing_started_at' => null,
                    'failed_at' => null,
                    'reconciliation_required_at' => null,
                    'next_retry_at' => null,
                    'failure_reason' => null,
                    'last_error_class' => null,
                    'last_error_code' => null,
                    'reconciliation_resolution' => $resolution,
                    'last_state_changed_at' => $resolvedAt,
                ],
            );

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

    /** @param array<string, mixed> $providerSync */
    private function providerName(
        array $providerSync,
        WebinarRegistration $registration,
    ): string {
        $provider = $this->nullableString($providerSync['provider'] ?? null)
            ?? $this->nullableString($registration->webinar?->providerKey());

        return $provider ?? 'unknown';
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
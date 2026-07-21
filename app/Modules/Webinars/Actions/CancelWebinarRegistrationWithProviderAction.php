<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\WebinarProviderCancellationResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class CancelWebinarRegistrationWithProviderAction
{
    private const IN_PROGRESS_STALE_AFTER_MINUTES = 10;

    public function __construct(
        private readonly WebinarProviderManager $webinarProviderManager,
    ) {}

    public function handle(WebinarRegistration $registration): WebinarProviderCancellationResult
    {
        $claim = $this->claimAttempt($registration);

        if ($claim instanceof WebinarProviderCancellationResult) {
            return $claim;
        }

        $provider = $claim;
        $registration = $registration->fresh(['webinar']) ?? $registration;

        if (! $this->providerRegistrantId($registration)) {
            $this->recordMissingProviderRegistration($registration);

            return new WebinarProviderCancellationResult(
                WebinarProviderCancellationResult::STATUS_FAILED,
                $provider,
            );
        }

        try {
            $webinar = $registration->webinar;

            if (! $webinar) {
                throw new \RuntimeException(
                    'Webinar registration provider cancellation requires its Webinar occurrence.',
                );
            }

            $this->webinarProviderManager
                ->forWebinar($webinar)
                ->cancelRegistration($registration);
        } catch (Throwable $exception) {
            $this->recordFailure($registration, $exception);
            report($exception);

            return new WebinarProviderCancellationResult(
                WebinarProviderCancellationResult::STATUS_FAILED,
                $provider,
            );
        }

        $this->recordSuccess($registration);

        return new WebinarProviderCancellationResult(
            WebinarProviderCancellationResult::STATUS_SUCCEEDED,
            $provider,
        );
    }

    private function claimAttempt(
        WebinarRegistration $registration,
    ): string|WebinarProviderCancellationResult {
        return DB::transaction(function () use ($registration): string|WebinarProviderCancellationResult {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $locked->loadMissing('webinar');

            $provider = $locked->webinar?->providerKey();
            $meta = is_array($locked->meta) ? $locked->meta : [];
            $state = is_array($meta['provider_cancellation'] ?? null)
                ? $meta['provider_cancellation']
                : [];

            if ($locked->status !== 'cancelled') {
                return new WebinarProviderCancellationResult(
                    WebinarProviderCancellationResult::STATUS_NOT_CANCELLED,
                    $provider,
                );
            }

            if (($state['status'] ?? null) === 'succeeded') {
                return new WebinarProviderCancellationResult(
                    WebinarProviderCancellationResult::STATUS_ALREADY_SUCCEEDED,
                    is_string($state['provider'] ?? null) ? $state['provider'] : $provider,
                );
            }

            if (($state['status'] ?? null) === 'not_required') {
                return new WebinarProviderCancellationResult(
                    WebinarProviderCancellationResult::STATUS_NOT_REQUIRED,
                    is_string($state['provider'] ?? null) ? $state['provider'] : $provider,
                );
            }

            if (! $locked->webinar || blank($provider) || blank($locked->webinar->external_id)) {
                $meta['provider_cancellation'] = array_replace($state, [
                    'status' => 'not_required',
                    'provider' => $provider,
                    'not_required_at' => now()->toISOString(),
                    'failed_at' => null,
                    'failure_stage' => null,
                    'last_error_class' => null,
                    'last_error_code' => null,
                ]);

                $locked->forceFill(['meta' => $meta])->save();

                return new WebinarProviderCancellationResult(
                    WebinarProviderCancellationResult::STATUS_NOT_REQUIRED,
                    $provider,
                );
            }

            if (
                ($state['status'] ?? null) === 'cancelling'
                && $this->isFreshTimestamp($state['last_attempted_at'] ?? null)
            ) {
                return new WebinarProviderCancellationResult(
                    WebinarProviderCancellationResult::STATUS_IN_PROGRESS,
                    $provider,
                );
            }

            $meta['provider_cancellation'] = array_replace($state, [
                'status' => 'cancelling',
                'provider' => $provider,
                'attempts' => ((int) ($state['attempts'] ?? 0)) + 1,
                'last_attempted_at' => now()->toISOString(),
                'failed_at' => null,
                'failure_stage' => null,
                'last_error_class' => null,
                'last_error_code' => null,
            ]);

            $locked->forceFill(['meta' => $meta])->save();

            return (string) $provider;
        });
    }

    private function recordSuccess(WebinarRegistration $registration): void
    {
        $this->updateClaimedState($registration, [
            'status' => 'succeeded',
            'succeeded_at' => now()->toISOString(),
            'failed_at' => null,
            'failure_stage' => null,
            'last_error_class' => null,
            'last_error_code' => null,
        ]);
    }

    private function recordMissingProviderRegistration(
        WebinarRegistration $registration,
    ): void {
        $this->updateClaimedState($registration, [
            'status' => 'failed',
            'failed_at' => now()->toISOString(),
            'failure_stage' => 'provider_registration_missing',
            'last_error_class' => null,
            'last_error_code' => null,
        ]);
    }

    private function recordFailure(
        WebinarRegistration $registration,
        Throwable $exception,
    ): void {
        $this->updateClaimedState($registration, [
            'status' => 'failed',
            'failed_at' => now()->toISOString(),
            'failure_stage' => 'provider_request',
            'last_error_class' => $exception::class,
            'last_error_code' => (string) $exception->getCode(),
        ]);
    }

    /** @param array<string, mixed> $changes */
    private function updateClaimedState(
        WebinarRegistration $registration,
        array $changes,
    ): void {
        DB::transaction(function () use ($registration, $changes): void {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $state = is_array($meta['provider_cancellation'] ?? null)
                ? $meta['provider_cancellation']
                : [];

            if (($state['status'] ?? null) !== 'cancelling') {
                return;
            }

            $meta['provider_cancellation'] = array_replace($state, $changes);
            $locked->forceFill(['meta' => $meta])->save();
        });
    }

    private function providerRegistrantId(WebinarRegistration $registration): ?string
    {
        $identifier = data_get($registration->meta, 'provider.data.registrant_id')
            ?? data_get($registration->meta, 'provider.registrant_id')
            ?? data_get($registration->meta, 'provider.id');

        return filled($identifier) ? (string) $identifier : null;
    }

    private function isFreshTimestamp(mixed $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        try {
            return Carbon::parse($value)->greaterThan(
                now()->subMinutes(self::IN_PROGRESS_STALE_AFTER_MINUTES),
            );
        } catch (Throwable) {
            return false;
        }
    }
}
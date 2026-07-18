<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\WebinarProviderCancellationResult;
use App\Modules\Webinars\Jobs\CancelWebinarRegistrationWithProviderJob;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueueWebinarProviderCancellationAction
{
    private const IN_PROGRESS_STALE_AFTER_MINUTES = 10;

    public function handle(WebinarRegistration $registration): WebinarProviderCancellationResult
    {
        $claim = $this->claimQueue($registration);

        if ($claim instanceof WebinarProviderCancellationResult) {
            return $claim;
        }

        try {
            CancelWebinarRegistrationWithProviderJob::dispatch(
                (int) $registration->getKey(),
            );
        } catch (Throwable $exception) {
            $this->recordDispatchFailure($registration, $exception);
            report($exception);

            return new WebinarProviderCancellationResult(
                WebinarProviderCancellationResult::STATUS_FAILED,
                $claim,
            );
        }

        return new WebinarProviderCancellationResult(
            WebinarProviderCancellationResult::STATUS_QUEUED,
            $claim,
        );
    }

    private function claimQueue(
        WebinarRegistration $registration,
    ): string|WebinarProviderCancellationResult|null {
        return DB::transaction(function () use ($registration): string|WebinarProviderCancellationResult|null {
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

            $status = $state['status'] ?? null;
            $activityAt = $status === 'cancelling'
                ? ($state['last_attempted_at'] ?? null)
                : ($state['last_queued_at'] ?? null);

            if (
                in_array($status, ['pending', 'cancelling'], true)
                && $this->isFreshTimestamp($activityAt)
            ) {
                return new WebinarProviderCancellationResult(
                    WebinarProviderCancellationResult::STATUS_IN_PROGRESS,
                    $provider,
                );
            }

            $queuedAt = now()->toISOString();
            $meta['provider_cancellation'] = array_replace($state, [
                'status' => 'pending',
                'provider' => $provider,
                'queue_attempts' => ((int) ($state['queue_attempts'] ?? 0)) + 1,
                'first_queued_at' => $state['first_queued_at'] ?? $queuedAt,
                'last_queued_at' => $queuedAt,
                'failed_at' => null,
                'failure_stage' => null,
                'last_error_class' => null,
                'last_error_code' => null,
            ]);

            $locked->forceFill(['meta' => $meta])->save();

            return $provider;
        });
    }

    private function recordDispatchFailure(
        WebinarRegistration $registration,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($registration, $exception): void {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $state = is_array($meta['provider_cancellation'] ?? null)
                ? $meta['provider_cancellation']
                : [];

            if (($state['status'] ?? null) !== 'pending') {
                return;
            }

            $meta['provider_cancellation'] = array_replace($state, [
                'status' => 'failed',
                'failed_at' => now()->toISOString(),
                'failure_stage' => 'queue_dispatch',
                'last_error_class' => $exception::class,
                'last_error_code' => (string) $exception->getCode(),
            ]);

            $locked->forceFill(['meta' => $meta])->save();
        });
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

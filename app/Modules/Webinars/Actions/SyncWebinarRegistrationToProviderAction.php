<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Data\WebinarProviderSyncResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncWebinarRegistrationToProviderAction
{
    private const IN_PROGRESS_STALE_AFTER_MINUTES = 10;

    public function __construct(
        private readonly AddRegistrantToWebinarProviderAction $addRegistrantToProvider,
    ) {}

    public function handle(WebinarRegistration $registration): WebinarProviderSyncResult
    {
        $registration->loadMissing('webinar');
        $webinar = $registration->webinar;

        if (! $webinar || blank($webinar->providerKey()) || blank($webinar->external_id)) {
            return new WebinarProviderSyncResult(
                WebinarProviderSyncResult::STATUS_NOT_REQUIRED,
                $webinar?->providerKey(),
            );
        }

        $claim = $this->claimAttempt($registration);

        if ($claim !== true) {
            return $claim;
        }

        try {
            $providerRegistration = $this->addRegistrantToProvider->handle(
                $webinar,
                $registration->fresh(['contact', 'webinar']) ?? $registration,
            );
        } catch (Throwable $exception) {
            $this->recordFailure($registration, $exception);
            report($exception);

            return new WebinarProviderSyncResult(
                WebinarProviderSyncResult::STATUS_FAILED,
                $webinar->providerKey(),
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

            $meta['provider'] = $providerRegistration->toMeta();
            $meta['provider_sync'] = array_replace($sync, [
                'status' => 'succeeded',
                'provider' => $providerRegistration->provider,
                'succeeded_at' => now()->toISOString(),
                'failed_at' => null,
                'last_error_class' => null,
                'last_error_code' => null,
            ]);

            $locked->forceFill(['meta' => $meta])->save();
        });

        return new WebinarProviderSyncResult(
            WebinarProviderSyncResult::STATUS_SUCCEEDED,
            $providerRegistration->provider,
        );
    }

    private function claimAttempt(
        WebinarRegistration $registration,
    ): true|WebinarProviderSyncResult {
        return DB::transaction(function () use ($registration): true|WebinarProviderSyncResult {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $sync = is_array($meta['provider_sync'] ?? null)
                ? $meta['provider_sync']
                : [];

            if (($sync['status'] ?? null) === 'succeeded') {
                return new WebinarProviderSyncResult(
                    WebinarProviderSyncResult::STATUS_ALREADY_SUCCEEDED,
                    is_string($sync['provider'] ?? null) ? $sync['provider'] : null,
                );
            }

            $lastAttemptedAt = isset($sync['last_attempted_at'])
                ? Carbon::parse((string) $sync['last_attempted_at'])
                : null;

            if (
                ($sync['status'] ?? null) === 'syncing'
                && $lastAttemptedAt
                && $lastAttemptedAt->greaterThan(
                    now()->subMinutes(self::IN_PROGRESS_STALE_AFTER_MINUTES),
                )
            ) {
                return new WebinarProviderSyncResult(
                    WebinarProviderSyncResult::STATUS_IN_PROGRESS,
                    is_string($sync['provider'] ?? null) ? $sync['provider'] : null,
                );
            }

            $meta['provider_sync'] = array_replace($sync, [
                'status' => 'syncing',
                'provider' => $locked->webinar?->providerKey(),
                'attempts' => ((int) ($sync['attempts'] ?? 0)) + 1,
                'last_attempted_at' => now()->toISOString(),
            ]);

            $locked->forceFill(['meta' => $meta])->save();

            return true;
        });
    }

    private function recordFailure(
        WebinarRegistration $registration,
        Throwable $exception,
    ): void {
        DB::transaction(function () use ($registration, $exception): void {
            $locked = WebinarRegistration::query()
                ->lockForUpdate()
                ->findOrFail($registration->getKey());

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $sync = is_array($meta['provider_sync'] ?? null)
                ? $meta['provider_sync']
                : [];

            $meta['provider_sync'] = array_replace($sync, [
                'status' => 'failed',
                'failed_at' => now()->toISOString(),
                'last_error_class' => $exception::class,
                'last_error_code' => (string) $exception->getCode(),
            ]);

            $locked->forceFill(['meta' => $meta])->save();
        });
    }
}

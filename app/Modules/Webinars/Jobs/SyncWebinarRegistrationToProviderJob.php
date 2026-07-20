<?php

namespace App\Modules\Webinars\Jobs;

use App\Modules\Webinars\Actions\FinalizeWebinarRegistrationAction;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncWebinarRegistrationToProviderJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries;

    public function __construct(
        public readonly int $registrationId,
    ) {
        $this->tries = max(
            1,
            (int) config(
                'webinars.registration.finalization.job_tries',
                5,
            ),
        );

        $this->onQueue((string) config(
            'webinars.queues.registration',
            'webinars',
        ));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        $backoff = config(
            'webinars.registration.finalization.job_backoff_seconds',
            [60, 300, 900, 3600],
        );

        if (! is_array($backoff)) {
            return [60, 300, 900, 3600];
        }

        $normalized = array_values(array_filter(array_map(
            fn (mixed $seconds): ?int => is_numeric($seconds)
                ? max(1, (int) $seconds)
                : null,
            $backoff,
        )));

        return $normalized !== []
            ? $normalized
            : [60, 300, 900, 3600];
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('webinar-registration-finalization:'.$this->registrationId))
                ->releaseAfter($this->inProgressReleaseSeconds())
                ->expireAfter($this->overlapExpirySeconds()),
        ];
    }

    public function handle(
        FinalizeWebinarRegistrationAction $finalizeRegistration,
    ): void {
        $registration = WebinarRegistration::query()
            ->with(['contact', 'webinar', 'webinar.webinarSeries'])
            ->find($this->registrationId);

        if (! $registration instanceof WebinarRegistration) {
            return;
        }

        $result = $finalizeRegistration->handle($registration);

        if (! $result instanceof WebinarRegistrationFinalizationResult) {
            return;
        }

        if ($result->inProgress()) {
            $this->release($this->inProgressReleaseSeconds());

            return;
        }

        if ($result->shouldRetry()) {
            throw new RuntimeException(
                'Webinar registration finalization is not complete: '.(
                    $result->reason ?? 'retry_required'
                ),
            );
        }
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $registration = WebinarRegistration::query()
                ->lockForUpdate()
                ->find($this->registrationId);

            if (! $registration instanceof WebinarRegistration) {
                return;
            }

            $meta = is_array($registration->meta)
                ? $registration->meta
                : [];
            $state = is_array(
                $meta[WebinarRegistrationFinalizationResult::META_KEY] ?? null,
            )
                ? $meta[WebinarRegistrationFinalizationResult::META_KEY]
                : [];

            if (in_array($state['status'] ?? null, [
                'completed',
                'failed',
                'reconciliation_required',
            ], true)) {
                return;
            }

            $failedAt = now()->toISOString();

            $meta[WebinarRegistrationFinalizationResult::META_KEY] = array_replace(
                $state,
                [
                    'status' => 'failed',
                    'failed_at' => $failedAt,
                    'processing_started_at' => null,
                    'queued_at' => null,
                    'next_retry_at' => null,
                    'failure_reason' => 'retry_exhausted',
                    'last_error_class' => $exception ? $exception::class : null,
                    'last_error_code' => $exception
                        ? (string) $exception->getCode()
                        : null,
                    'last_state_changed_at' => $failedAt,
                ],
            );

            $registration->forceFill(['meta' => $meta])->save();
        });
    }

    private function inProgressReleaseSeconds(): int
    {
        return max(
            5,
            (int) config(
                'webinars.registration.finalization.in_progress_release_seconds',
                30,
            ),
        );
    }

    private function overlapExpirySeconds(): int
    {
        return max(
            60,
            (int) config(
                'webinars.registration.finalization.overlap_expiry_seconds',
                900,
            ),
        );
    }
}
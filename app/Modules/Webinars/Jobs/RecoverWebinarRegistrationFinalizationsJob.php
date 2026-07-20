<?php

namespace App\Modules\Webinars\Jobs;

use App\Modules\Webinars\Actions\QueueWebinarRegistrationFinalizationAction;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class RecoverWebinarRegistrationFinalizationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue((string) config(
            'webinars.queues.registration_recovery',
            'default',
        ));
    }

    public function uniqueId(): string
    {
        return 'webinar-registration-finalization-recovery';
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(60)
                ->expireAfter(600),
        ];
    }

    public function handle(
        QueueWebinarRegistrationFinalizationAction $queueFinalization,
    ): void {
        $registrationIds = WebinarRegistration::query()
            ->where(function (Builder $query): void {
                foreach (['pending', 'queued', 'processing'] as $status) {
                    $query->orWhere(
                        'meta->'.WebinarRegistrationFinalizationResult::META_KEY.'->status',
                        $status,
                    );
                }
            })
            ->oldest('updated_at')
            ->limit($this->batchSize())
            ->pluck('id');

        foreach ($registrationIds as $registrationId) {
            $queueFinalization->handle((int) $registrationId);
        }
    }

    private function batchSize(): int
    {
        return max(
            1,
            (int) config(
                'webinars.registration.finalization.recovery_batch_size',
                100,
            ),
        );
    }
}
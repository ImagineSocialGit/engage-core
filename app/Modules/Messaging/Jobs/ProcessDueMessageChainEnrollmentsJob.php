<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Services\BulkMessageDeliveryPolicy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessDueMessageChainEnrollmentsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 55;

    public function uniqueId(): string
    {
        return 'messaging:due-message-chain-enrollments';
    }

    public function handle(): void
    {
        $bulkPolicy = app(BulkMessageDeliveryPolicy::class);
        $queue = $bulkPolicy->queue();

        MessageChainEnrollment::query()
            ->due()
            ->orderBy('next_action_at')
            ->orderBy('id')
            ->limit($bulkPolicy->chunkSize())
            ->pluck('id')
            ->each(
                fn (int $enrollmentId) =>
                    ProcessMessageChainEnrollmentJob::dispatch(
                        enrollmentId: $enrollmentId,
                    )->onQueue($queue),
            );
    }
}
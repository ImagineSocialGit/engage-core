<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Models\MessageChainEnrollment;
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
        MessageChainEnrollment::query()
            ->due()
            ->orderBy('next_action_at')
            ->orderBy('id')
            ->limit(500)
            ->pluck('id')
            ->each(
                fn (int $enrollmentId) =>
                    ProcessMessageChainEnrollmentJob::dispatch(
                        enrollmentId: $enrollmentId,
                    ),
            );
    }
}
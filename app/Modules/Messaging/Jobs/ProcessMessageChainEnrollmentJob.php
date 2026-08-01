<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Actions\ProcessMessageChainEnrollmentAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMessageChainEnrollmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [15, 60, 300];

    public function __construct(
        public readonly int $enrollmentId,
    ) {}

    public function handle(
        ProcessMessageChainEnrollmentAction $processEnrollment,
    ): void {
        $processEnrollment->handle($this->enrollmentId);
    }
}
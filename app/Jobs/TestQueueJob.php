<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TestQueueJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $message = 'Queue test ran'
    ) {}

    public function handle(): void
    {
        file_put_contents(storage_path('logs/queue-test.log'), now() . " ran\n", FILE_APPEND);
    }
}

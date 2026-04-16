<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FailingQueueJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        throw new \RuntimeException('Intentional queue failure');
    }
}
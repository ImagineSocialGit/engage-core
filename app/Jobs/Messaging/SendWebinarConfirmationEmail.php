<?php

namespace App\Jobs\Messaging;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendWebinarConfirmationEmail implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $registrationId
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {

    }
}
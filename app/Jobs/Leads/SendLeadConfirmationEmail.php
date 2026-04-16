<?php

namespace App\Jobs\Leads;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendLeadConfirmationEmail implements ShouldQueue
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
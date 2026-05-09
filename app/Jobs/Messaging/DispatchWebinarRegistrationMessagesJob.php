<?php

namespace App\Jobs\Messaging;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class DispatchWebinarRegistrationMessagesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public array $payload
    ) {}

    public function handle(): void
    {
        SendWebinarConfirmationEmailJob::dispatch($this->payload)
            ->onQueue(config('webinars.queues.confirmation_messages'));

        SendWebinarConfirmationSmsJob::dispatch($this->payload)
            ->onQueue(config('webinars.queues.confirmation_messages'));
    }
}

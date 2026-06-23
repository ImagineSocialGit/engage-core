<?php

namespace App\Events\Messaging;

use App\Models\ScheduledMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ScheduledMessageSent
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ScheduledMessage $scheduledMessage,
    ) {}
}
<?php

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Models\ScheduledMessage;
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
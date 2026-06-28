<?php

namespace App\Support\AutomationEvents\Events;

use App\Support\AutomationEvents\Data\AutomationEventData;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AutomationEventRecorded
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly AutomationEventData $event,
    ) {}
}
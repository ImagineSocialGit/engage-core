<?php

namespace App\Modules\InboundMessaging\Events;

use App\Modules\InboundMessaging\Models\InboundMessage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InboundMessageReceived
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly InboundMessage $inboundMessage,
    ) {}
}
<?php

namespace App\Modules\InboundMessaging\Actions\Sms\Inbound;

use App\Modules\InboundMessaging\Contracts\InboundMessageHandler;
use App\Modules\InboundMessaging\Models\InboundMessage;

class RespondToSmsHelpInboundMessageAction implements InboundMessageHandler
{
    public function handle(InboundMessage $inboundMessage): ?string
    {
        $inboundMessage->markProcessed();

        return config('messaging.sms.inbound.help_response');
    }
}
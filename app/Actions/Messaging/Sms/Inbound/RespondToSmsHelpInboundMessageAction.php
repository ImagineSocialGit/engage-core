<?php

namespace App\Actions\Messaging\Sms\Inbound;

use App\Contracts\Messaging\InboundMessageHandler;
use App\Models\InboundMessage;

class RespondToSmsHelpInboundMessageAction implements InboundMessageHandler
{
    public function handle(InboundMessage $inboundMessage): ?string
    {
        $inboundMessage->markProcessed();

        return config('messaging.sms.inbound.help_response');
    }
}
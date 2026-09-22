<?php

namespace App\Modules\InboundMessaging\Actions\Inbox;

use App\Modules\InboundMessaging\Models\InboundMessage;

final class DeleteInboundMessageAction
{
    public function handle(InboundMessage $inboundMessage): void
    {
        $inboundMessage->delete();
    }
}
<?php

namespace App\Modules\InboundMessaging\Contracts;

use App\Modules\InboundMessaging\Models\InboundMessage;

interface InboundMessageHandler
{
    public function handle(InboundMessage $inboundMessage): ?string;
}
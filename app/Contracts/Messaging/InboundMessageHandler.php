<?php

namespace App\Contracts\Messaging;

use App\Models\InboundMessage;

interface InboundMessageHandler
{
    public function handle(InboundMessage $inboundMessage): ?string;
}
<?php

namespace App\Modules\InboundMessaging\Actions\Sms;

use App\Modules\InboundMessaging\Actions\ProcessInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\InboundMessageRouter;

class ProcessInboundSmsMessageAction
{
    private readonly ProcessInboundMessageAction $processInboundMessageAction;

    public function __construct(
        InboundMessageRouter $inboundMessageRouter,
    ) {
        $this->processInboundMessageAction = new ProcessInboundMessageAction(
            $inboundMessageRouter,
        );
    }

    public function handle(InboundMessage $inboundMessage): ?string
    {
        return $this->processInboundMessageAction->handle($inboundMessage);
    }
}
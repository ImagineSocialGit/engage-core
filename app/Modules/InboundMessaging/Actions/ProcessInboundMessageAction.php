<?php

namespace App\Modules\InboundMessaging\Actions;

use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\InboundMessageRouter;
use Illuminate\Support\Facades\DB;

class ProcessInboundMessageAction
{
    public function __construct(
        private readonly InboundMessageRouter $inboundMessageRouter,
    ) {}

    public function handle(InboundMessage $inboundMessage): ?string
    {
        return DB::transaction(function () use ($inboundMessage): ?string {
            $message = InboundMessage::query()
                ->lockForUpdate()
                ->findOrFail($inboundMessage->getKey());

            if ($message->processed_at !== null) {
                return null;
            }

            event(new InboundMessageReceived($message));

            return $this->inboundMessageRouter->route($message);
        }, 3);
    }
}
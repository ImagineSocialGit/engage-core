<?php

namespace App\Modules\InboundMessaging\Listeners;

use App\Modules\InboundMessaging\Data\InboundEmailRouteIdentity;
use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InboundMessaging\Services\Email\RoutedInboundMessageConsumerRegistry;

final class ConsumeRoutedInboundMessage
{
    public function __construct(
        private readonly RoutedInboundMessageConsumerRegistry $consumers,
    ) {}

    public function handle(InboundMessageReceived $event): void
    {
        $message = $event->inboundMessage;
        $route = InboundEmailRouteIdentity::fromMessage($message);

        if ($route === null) {
            return;
        }

        $consumer = $this->consumers->resolve($route);

        if ($consumer === null) {
            return;
        }

        $result = $consumer->consume(
            message: $message,
            route: $route,
        );

        $changes = [];

        if ($result->relatedContact !== null
            && (int) $message->related_contact_id
                !== (int) $result->relatedContact->getKey()
        ) {
            $changes['related_contact_id'] =
                $result->relatedContact->getKey();
        }

        if ($result->isHandled()
            && $message->processed_at === null
        ) {
            $changes['processed_at'] = now();
        }

        if ($changes !== []) {
            $message->forceFill($changes)->save();
        }
    }
}
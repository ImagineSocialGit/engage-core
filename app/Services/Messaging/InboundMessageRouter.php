<?php

namespace App\Services\Messaging;

use App\Contracts\Messaging\InboundMessageHandler;
use App\Models\InboundMessage;
use BackedEnum;
use InvalidArgumentException;

class InboundMessageRouter
{
    public function route(InboundMessage $inboundMessage): ?string
    {
        $responseMessage = null;

        foreach ($this->handlersFor($inboundMessage) as $handlerClass) {
            $handler = app($handlerClass);

            if (! $handler instanceof InboundMessageHandler) {
                throw new InvalidArgumentException(
                    sprintf('[%s] must implement [%s].', $handlerClass, InboundMessageHandler::class)
                );
            }

            $result = $handler->handle($inboundMessage);

            if ($responseMessage === null && $result !== null) {
                $responseMessage = $result;
            }
        }

        return $responseMessage;
    }

    private function handlersFor(InboundMessage $inboundMessage): array
    {
        $channel = $this->value($inboundMessage->channel);

        if ($channel === null) {
            return [];
        }

        return config(
            "messaging.inbound.handlers.{$channel}.{$inboundMessage->classification}",
            [],
        );
    }

    private function value(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }
}
<?php

namespace App\Modules\InboundMessaging\Data;

use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Models\InboundMessage;
use BackedEnum;

final readonly class InboundEmailRouteIdentity
{
    public function __construct(
        public string $routeKey,
        public ?string $source = null,
        public ?string $contextKey = null,
    ) {}

    public static function fromRoute(InboundEmailRoute $route): self
    {
        return new self(
            routeKey: trim((string) $route->key),
            source: self::nullableString($route->source),
            contextKey: self::nullableString($route->context_key),
        );
    }

    public static function fromMessage(
        InboundMessage $message,
    ): ?self {
        if (self::channelValue($message->channel) !== 'email') {
            return null;
        }

        $routeKey = self::nullableString(
            $message->inbound_email_route_key,
        );

        if ($routeKey === null) {
            return null;
        }

        return new self(
            routeKey: $routeKey,
            source: self::nullableString(
                $message->inbound_email_route_source,
            ),
            contextKey: self::nullableString(
                $message->inbound_email_route_context,
            ),
        );
    }

    private static function channelValue(mixed $channel): string
    {
        return $channel instanceof BackedEnum
            ? (string) $channel->value
            : trim((string) $channel);
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
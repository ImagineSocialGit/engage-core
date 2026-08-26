<?php

namespace App\Modules\InboundMessaging\Contracts;

use App\Modules\InboundMessaging\Data\InboundEmailRouteIdentity;
use App\Modules\InboundMessaging\Data\RoutedInboundMessageConsumeResult;
use App\Modules\InboundMessaging\Models\InboundMessage;

interface RoutedInboundMessageConsumer
{
    /**
     * Stable internal identity for diagnostics and conflict detection.
     */
    public function key(): string;

    /**
     * Plain-language label suitable for operator-facing "Connected to" presentation.
     */
    public function label(): string;

    /**
     * Return true only for named inbound-address traffic owned by this consumer.
     *
     * Consumers should match durable internal route identity/configuration, never
     * sender/body heuristics.
     */
    public function claims(InboundEmailRouteIdentity $route): bool;

    /**
     * Consume one already-normalized inbound message.
     *
     * Implementations must be idempotent for the InboundMessage primary key.
     * Throw for retryable/system failures. Return unresolved() when the message
     * is valid but cannot yet be attached to owning-module business identity.
     */
    public function consume(
        InboundMessage $message,
        InboundEmailRouteIdentity $route,
    ): RoutedInboundMessageConsumeResult;
}
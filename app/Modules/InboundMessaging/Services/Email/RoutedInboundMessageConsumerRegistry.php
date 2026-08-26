<?php

namespace App\Modules\InboundMessaging\Services\Email;

use App\Modules\InboundMessaging\Contracts\RoutedInboundMessageConsumer;
use App\Modules\InboundMessaging\Data\InboundEmailRouteIdentity;
use LogicException;

final class RoutedInboundMessageConsumerRegistry
{
    public const CONSUMER_TAG = 'inbound_messaging.routed_message_consumers';

    /**
     * @var array<int, RoutedInboundMessageConsumer>
     */
    private array $consumers;

    public function __construct(iterable $consumers = [])
    {
        $this->consumers = [];

        foreach ($consumers as $consumer) {
            if (! $consumer instanceof RoutedInboundMessageConsumer) {
                throw new LogicException(
                    'Routed inbound-message consumers must implement '
                    .RoutedInboundMessageConsumer::class.'.',
                );
            }

            $this->consumers[] = $consumer;
        }
    }

    /**
     * @return array<int, RoutedInboundMessageConsumer>
     */
    public function all(): array
    {
        return $this->consumers;
    }

    /**
     * @return array<int, RoutedInboundMessageConsumer>
     */
    public function matching(
        InboundEmailRouteIdentity $route,
    ): array {
        return array_values(array_filter(
            $this->consumers,
            fn (RoutedInboundMessageConsumer $consumer): bool =>
                $consumer->claims($route),
        ));
    }

    public function resolve(
        InboundEmailRouteIdentity $route,
    ): ?RoutedInboundMessageConsumer {
        $matches = $this->matching($route);

        if (count($matches) > 1) {
            throw new LogicException(sprintf(
                'Inbound email route [%s] is claimed by multiple routed-message consumers: %s.',
                $route->routeKey,
                implode(', ', array_map(
                    fn (RoutedInboundMessageConsumer $consumer): string =>
                        $this->consumerKey($consumer),
                    $matches,
                )),
            ));
        }

        return $matches[0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function duplicateKeys(): array
    {
        $counts = [];

        foreach ($this->consumers as $consumer) {
            $key = $this->consumerKey($consumer);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return array_values(array_keys(array_filter(
            $counts,
            static fn (int $count): bool => $count > 1,
        )));
    }

    public function consumerKey(
        RoutedInboundMessageConsumer $consumer,
    ): string {
        $key = trim($consumer->key());

        if ($key === '') {
            throw new LogicException(
                'Routed inbound-message consumer keys cannot be empty.',
            );
        }

        return $key;
    }

    public function consumerLabel(
        RoutedInboundMessageConsumer $consumer,
    ): string {
        $label = trim($consumer->label());

        return $label !== ''
            ? $label
            : 'Connected business process';
    }
}
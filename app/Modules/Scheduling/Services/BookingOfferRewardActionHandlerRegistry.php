<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Contracts\BookingOfferRewardActionHandler;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use LogicException;

final class BookingOfferRewardActionHandlerRegistry
{
    public const TAG = 'scheduling.booking_offer_reward_action_handlers';

    public function __construct(
        private readonly Container $container,
    ) {}

    /** @return array<string, BookingOfferRewardActionHandler> */
    public function all(): array
    {
        $handlers = [];

        foreach ($this->container->tagged(self::TAG) as $handler) {
            if (! $handler instanceof BookingOfferRewardActionHandler) {
                throw new LogicException(
                    'Scheduling booking offer reward handlers must implement BookingOfferRewardActionHandler.',
                );
            }

            $key = trim($handler->key());

            if ($key === '') {
                throw new LogicException(
                    'Scheduling booking offer reward handlers require a non-empty key.',
                );
            }

            if (isset($handlers[$key])) {
                throw new LogicException(
                    "Duplicate Scheduling booking offer reward handler [{$key}].",
                );
            }

            $handlers[$key] = $handler;
        }

        ksort($handlers);

        return $handlers;
    }

    public function handler(string $key): BookingOfferRewardActionHandler
    {
        $key = trim($key);
        $handler = $this->all()[$key] ?? null;

        if (! $handler instanceof BookingOfferRewardActionHandler) {
            throw new InvalidArgumentException(
                "Scheduling booking offer reward handler [{$key}] is unavailable.",
            );
        }

        return $handler;
    }

    /** @param array<string, mixed> $payload */
    public function apply(
        string $provider,
        Contact $contact,
        Appointment $appointment,
        array $payload,
    ): void {
        $this->handler($provider)->apply($contact, $appointment, $payload);
    }
}
<?php

namespace App\Modules\Commerce\Data;

use App\Modules\Commerce\Models\CommerceOrderEvent;
use DateTimeInterface;
use InvalidArgumentException;

final readonly class CommerceOrderSyncRequest
{
    /**
     * @param array<string, mixed> $eventMeta
     */
    public function __construct(
        public string $externalOrderId,
        public ?string $scope = null,
        public string $event = CommerceOrderEvent::EVENT_SYNCED,
        public ?string $providerEventId = null,
        public ?DateTimeInterface $occurredAt = null,
        public array $eventMeta = [],
    ) {
        if (trim($this->externalOrderId) === '') {
            throw new InvalidArgumentException(
                'Commerce order sync external identity cannot be empty.',
            );
        }

        if (! in_array($this->event, [
            CommerceOrderEvent::EVENT_CREATED,
            CommerceOrderEvent::EVENT_UPDATED,
            CommerceOrderEvent::EVENT_PAID,
            CommerceOrderEvent::EVENT_CANCELLED,
            CommerceOrderEvent::EVENT_REFUNDED,
            CommerceOrderEvent::EVENT_FULFILLED,
            CommerceOrderEvent::EVENT_SYNCED,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported Commerce order event [{$this->event}].",
            );
        }

        if ($this->providerEventId !== null
            && trim($this->providerEventId) === ''
        ) {
            throw new InvalidArgumentException(
                'Commerce order provider event identity cannot be blank when supplied.',
            );
        }
    }
}
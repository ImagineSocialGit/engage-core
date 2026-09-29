<?php

namespace App\Modules\Commerce\Data;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class CommercePurchaseConfirmation
{
    /**
     * @param array<int, int> $commerceOrderItemIds
     */
    public function __construct(
        public int $commerceOrderId,
        public array $commerceOrderItemIds,
        public ?int $contactId,
        public ?int $commerceCustomerId,
        public string $providerKey,
        public string $source,
        public string $externalOrderId,
        public CarbonImmutable $occurredAt,
    ) {
        if ($this->commerceOrderId < 1) {
            throw new InvalidArgumentException(
                'Commerce purchase confirmation requires a valid order identity.',
            );
        }

        if ($this->commerceOrderItemIds === []) {
            throw new InvalidArgumentException(
                'Commerce purchase confirmation requires at least one order item.',
            );
        }

        $uniqueItemIds = [];

        foreach ($this->commerceOrderItemIds as $itemId) {
            if (! is_int($itemId) || $itemId < 1) {
                throw new InvalidArgumentException(
                    'Commerce purchase confirmation contains an invalid order-item identity.',
                );
            }

            $uniqueItemIds[$itemId] = true;
        }

        if (count($uniqueItemIds) !== count($this->commerceOrderItemIds)) {
            throw new InvalidArgumentException(
                'Commerce purchase confirmation order-item identities must be unique.',
            );
        }

        if ($this->contactId !== null && $this->contactId < 1) {
            throw new InvalidArgumentException(
                'Commerce purchase confirmation contains an invalid Contact identity.',
            );
        }

        if ($this->commerceCustomerId !== null && $this->commerceCustomerId < 1) {
            throw new InvalidArgumentException(
                'Commerce purchase confirmation contains an invalid Commerce customer identity.',
            );
        }

        if (trim($this->providerKey) === '') {
            throw new InvalidArgumentException(
                'Commerce purchase confirmation requires a provider key.',
            );
        }

        if (trim($this->source) === '') {
            throw new InvalidArgumentException(
                'Commerce purchase confirmation requires a source.',
            );
        }

        if (trim($this->externalOrderId) === '') {
            throw new InvalidArgumentException(
                'Commerce purchase confirmation requires an external order identity.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'commerce_order_id' => $this->commerceOrderId,
            'commerce_order_item_ids' => $this->commerceOrderItemIds,
            'contact_id' => $this->contactId,
            'commerce_customer_id' => $this->commerceCustomerId,
            'provider_key' => $this->providerKey,
            'source' => $this->source,
            'external_order_id' => $this->externalOrderId,
            'occurred_at' => $this->occurredAt->toISOString(),
        ];
    }
}
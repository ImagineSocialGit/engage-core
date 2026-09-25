<?php

namespace App\Modules\Commerce\Data;

final readonly class CommerceOrderSyncResult
{
    public function __construct(
        public string $providerKey,
        public int $commerceOrderId,
        public bool $orderCreated,
        public bool $orderChanged,
        public bool $orderUnchanged,
        public int $itemsCreated,
        public int $itemsChanged,
        public int $itemsUnchanged,
        public int $itemsRemoved,
        public bool $eventCreated,
    ) {}
}
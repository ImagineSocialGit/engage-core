<?php

namespace App\Modules\Messaging\Data\Delivery;

final readonly class MessageDeliveryComponent
{
    public function __construct(
        public string $channel,
        public int $messageTemplateVersionId,
        public string $role,
        public string $intentKey,
        public ?int $messageConsentId,
        public int $sortOrder,
        public string $placementKey,
        public MessageDeliveryIntent $standaloneIntent,
    ) {}
}
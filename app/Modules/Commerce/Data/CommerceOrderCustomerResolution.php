<?php

namespace App\Modules\Commerce\Data;

final readonly class CommerceOrderCustomerResolution
{
    public function __construct(
        public ?int $commerceCustomerId,
        public ?int $contactId,
    ) {}
}
<?php

namespace App\Modules\Scheduling\Data;

use InvalidArgumentException;

final readonly class BookingEligibilityIdentity
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public int $contactId,
        public string $scopeKey,
        public array $meta = [],
    ) {
        if ($this->contactId < 1) {
            throw new InvalidArgumentException(
                'Booking eligibility identities require a positive contact ID.',
            );
        }

        if (trim($this->scopeKey) === '' || mb_strlen($this->scopeKey) > 150) {
            throw new InvalidArgumentException(
                'Booking eligibility identities require a non-empty scope key of 150 characters or fewer.',
            );
        }
    }
}
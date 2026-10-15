<?php

namespace App\Modules\Billing\Data;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class BillingCollectionResult
{
    public function __construct(
        public string $providerKey,
        public string $externalCollectionId,
        public string $nextUrl,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        if (trim($this->providerKey) === '' || trim($this->externalCollectionId) === '') {
            throw new InvalidArgumentException(
                'Billing collection results require provider and external collection identities.',
            );
        }

        if (filter_var($this->nextUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException(
                'Billing collection next URL must be a valid absolute URL.',
            );
        }
    }
}
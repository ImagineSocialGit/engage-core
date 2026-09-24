<?php

namespace App\Modules\Commerce\Data;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class CommerceCheckoutResult
{
    public function __construct(
        public string $providerKey,
        public string $externalCheckoutId,
        public string $nextUrl,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        if (trim($this->providerKey) === '' || trim($this->externalCheckoutId) === '') {
            throw new InvalidArgumentException('Commerce checkout results require provider and external checkout identities.');
        }

        if (filter_var($this->nextUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Commerce checkout next URL must be a valid absolute URL.');
        }
    }
}
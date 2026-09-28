<?php

namespace App\Modules\Commerce\Data;

use InvalidArgumentException;

final readonly class CommerceOrderCustomerSnapshotData
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public ?string $externalId = null,
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $name = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $currency = null,
        public ?int $totalOrders = null,
        public ?int $totalSpentCents = null,
        public ?string $externalUrl = null,
        public array $meta = [],
    ) {
        if ($this->externalId !== null && trim($this->externalId) === '') {
            throw new InvalidArgumentException(
                'Commerce order customer external identity cannot be blank when supplied.',
            );
        }

        if ($this->email !== null) {
            $email = trim($this->email);

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException(
                    'Commerce order customer email must be valid when supplied.',
                );
            }
        }

        if ($this->currency !== null
            && preg_match('/^[A-Za-z]{3}$/D', trim($this->currency)) !== 1
        ) {
            throw new InvalidArgumentException(
                'Commerce order customer currency must be a three-letter code when supplied.',
            );
        }

        if ($this->totalOrders !== null && $this->totalOrders < 0) {
            throw new InvalidArgumentException(
                'Commerce order customer total orders cannot be negative.',
            );
        }

        if ($this->totalSpentCents !== null && $this->totalSpentCents < 0) {
            throw new InvalidArgumentException(
                'Commerce order customer total spent cents cannot be negative.',
            );
        }

        if ($this->externalId === null && $this->email === null) {
            throw new InvalidArgumentException(
                'Commerce order customer snapshot requires a provider customer identity or email address.',
            );
        }
    }
}
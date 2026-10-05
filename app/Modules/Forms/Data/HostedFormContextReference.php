<?php

namespace App\Modules\Forms\Data;

use InvalidArgumentException;

final readonly class HostedFormContextReference
{
    public string $key;

    public string $reference;

    public function __construct(string $key, string|int $reference)
    {
        $key = strtolower(trim($key));

        if (preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $key) !== 1) {
            throw new InvalidArgumentException(
                'Hosted form context key must begin with a letter and contain only lowercase letters, numbers, dots, dashes, or underscores.',
            );
        }

        $reference = trim((string) $reference);

        if ($reference === '') {
            throw new InvalidArgumentException(
                'Hosted form context reference cannot be empty.',
            );
        }

        if (mb_strlen($reference) > 191) {
            throw new InvalidArgumentException(
                'Hosted form context reference cannot exceed 191 characters.',
            );
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            throw new InvalidArgumentException(
                'Hosted form context reference cannot contain control characters.',
            );
        }

        $this->key = $key;
        $this->reference = $reference;
    }

    /** @return array{key: string, reference: string} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'reference' => $this->reference,
        ];
    }
}
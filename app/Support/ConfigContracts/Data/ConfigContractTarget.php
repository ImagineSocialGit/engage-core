<?php

namespace App\Support\ConfigContracts\Data;

use InvalidArgumentException;

final class ConfigContractTarget
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $contractKey,
        public readonly string $path,
        public readonly mixed $value,
        public readonly array $context = [],
    ) {
        if (trim($contractKey) === '') {
            throw new InvalidArgumentException('Config contract target contract key must be a non-empty string.');
        }

        if (trim($path) === '') {
            throw new InvalidArgumentException('Config contract target path must be a non-empty string.');
        }
    }

    public function identity(): string
    {
        return $this->contractKey.'|'.$this->path;
    }
}

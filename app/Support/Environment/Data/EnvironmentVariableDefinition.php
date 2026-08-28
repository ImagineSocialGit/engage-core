<?php

namespace App\Support\Environment\Data;

use InvalidArgumentException;

final readonly class EnvironmentVariableDefinition
{
    public const SCOPE_ROOT = 'root';
    public const SCOPE_CLIENT = 'client';

    public function __construct(
        public string $key,
        public string $scope,
        public string $owner,
        public bool $secret = false,
    ) {
        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $this->key) !== 1) {
            throw new InvalidArgumentException(
                "Invalid environment variable key [{$this->key}].",
            );
        }

        if (! in_array($this->scope, [self::SCOPE_ROOT, self::SCOPE_CLIENT], true)) {
            throw new InvalidArgumentException(
                "Invalid environment variable scope [{$this->scope}] for [{$this->key}].",
            );
        }

        if (trim($this->owner) === '') {
            throw new InvalidArgumentException(
                "Environment variable [{$this->key}] must declare an owner.",
            );
        }
    }
}
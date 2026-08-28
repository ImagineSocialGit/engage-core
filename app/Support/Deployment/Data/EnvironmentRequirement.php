<?php

namespace App\Support\Deployment\Data;

use InvalidArgumentException;

final readonly class EnvironmentRequirement
{
    public const REQUIRED = 'required';
    public const OPTIONAL = 'optional';
    public const DEFAULTED = 'defaulted';

    public function __construct(
        public string $key,
        public string $requirement,
        public string $reason,
        public ?string $expectedValue = null,
    ) {
        if (! in_array($this->requirement, [self::REQUIRED, self::OPTIONAL, self::DEFAULTED], true)) {
            throw new InvalidArgumentException(
                "Unsupported environment requirement [{$this->requirement}] for [{$this->key}].",
            );
        }

        if (trim($this->reason) === '') {
            throw new InvalidArgumentException(
                "Environment requirement [{$this->key}] must include a reason.",
            );
        }
    }

    public static function required(
        string $key,
        string $reason,
        ?string $expectedValue = null,
    ): self {
        return new self($key, self::REQUIRED, $reason, $expectedValue);
    }

    public static function optional(string $key, string $reason): self
    {
        return new self($key, self::OPTIONAL, $reason);
    }

    public static function defaulted(string $key, string $reason): self
    {
        return new self($key, self::DEFAULTED, $reason);
    }

    public function isRequired(): bool
    {
        return $this->requirement === self::REQUIRED;
    }
}
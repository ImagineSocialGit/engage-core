<?php

namespace App\Support\Deployment\Data;

use InvalidArgumentException;

final readonly class EnvironmentRequirement
{
    public const REQUIRED = 'required';
    public const OPTIONAL = 'optional';
    public const DEFAULTED = 'defaulted';

    /**
     * @param array<int, string> $allowedValues
     */
    public function __construct(
        public string $key,
        public string $requirement,
        public string $reason,
        public ?string $expectedValue = null,
        public array $allowedValues = [],
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

        $normalizedAllowedValues = [];

        foreach ($this->allowedValues as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException(
                    "Environment requirement [{$this->key}] contains an invalid allowed value.",
                );
            }

            $value = trim($value);

            if (! in_array($value, $normalizedAllowedValues, true)) {
                $normalizedAllowedValues[] = $value;
            }
        }

        if ($normalizedAllowedValues !== $this->allowedValues) {
            throw new InvalidArgumentException(
                "Environment requirement [{$this->key}] allowed values must already be trimmed and unique.",
            );
        }

        if ($this->expectedValue !== null
            && $this->allowedValues !== []
            && ! in_array($this->expectedValue, $this->allowedValues, true)
        ) {
            throw new InvalidArgumentException(
                "Environment requirement [{$this->key}] expected value must be one of its allowed values.",
            );
        }
    }

    /**
     * @param array<int, string> $allowedValues
     */
    public static function required(
        string $key,
        string $reason,
        ?string $expectedValue = null,
        array $allowedValues = [],
    ): self {
        return new self(
            key: $key,
            requirement: self::REQUIRED,
            reason: $reason,
            expectedValue: $expectedValue,
            allowedValues: $allowedValues,
        );
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
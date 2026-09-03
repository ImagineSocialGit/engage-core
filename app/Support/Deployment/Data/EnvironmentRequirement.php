<?php

namespace App\Support\Deployment\Data;

use InvalidArgumentException;

final readonly class EnvironmentRequirement
{
    public const REQUIRED = 'required';
    public const OPTIONAL = 'optional';
    public const DEFAULTED = 'defaulted';

    public const VALUE_RULE_HTTP_ORIGIN = 'http_origin';
    public const VALUE_RULE_EMAIL_DOMAIN = 'email_domain';

    /**
     * @param array<int, string> $allowedValues
     */
    public function __construct(
        public string $key,
        public string $requirement,
        public string $reason,
        public ?string $expectedValue = null,
        public array $allowedValues = [],
        public ?string $valueRule = null,
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

        if ($this->valueRule !== null
            && ! in_array($this->valueRule, self::valueRules(), true)
        ) {
            throw new InvalidArgumentException(
                "Unsupported environment value rule [{$this->valueRule}] for [{$this->key}].",
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
        ?string $valueRule = null,
    ): self {
        return new self(
            key: $key,
            requirement: self::REQUIRED,
            reason: $reason,
            expectedValue: $expectedValue,
            allowedValues: $allowedValues,
            valueRule: $valueRule,
        );
    }

    public static function optional(
        string $key,
        string $reason,
        ?string $valueRule = null,
    ): self {
        return new self(
            key: $key,
            requirement: self::OPTIONAL,
            reason: $reason,
            valueRule: $valueRule,
        );
    }

    /**
     * @param array<int, string> $allowedValues
     */
    public static function defaulted(
        string $key,
        string $reason,
        array $allowedValues = [],
        ?string $valueRule = null,
    ): self {
        return new self(
            key: $key,
            requirement: self::DEFAULTED,
            reason: $reason,
            allowedValues: $allowedValues,
            valueRule: $valueRule,
        );
    }

    public function isRequired(): bool
    {
        return $this->requirement === self::REQUIRED;
    }

    /** @return array<int, string> */
    private static function valueRules(): array
    {
        return [
            self::VALUE_RULE_HTTP_ORIGIN,
            self::VALUE_RULE_EMAIL_DOMAIN,
        ];
    }
}
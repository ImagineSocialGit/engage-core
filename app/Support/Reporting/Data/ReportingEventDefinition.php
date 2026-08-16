<?php

namespace App\Support\Reporting\Data;

use InvalidArgumentException;

final readonly class ReportingEventDefinition
{
    public const SESSION_EXPECTED = 'expected';
    public const SESSION_OPTIONAL = 'optional';
    public const SESSION_NONE = 'none';

    public const SESSION_MODES = [
        self::SESSION_EXPECTED,
        self::SESSION_OPTIONAL,
        self::SESSION_NONE,
    ];

    public const PROPERTY_STRING = 'string';
    public const PROPERTY_INTEGER = 'integer';
    public const PROPERTY_BOOLEAN = 'boolean';
    public const PROPERTY_ENUM = 'enum';
    public const PROPERTY_STRING_LIST = 'string_list';

    public const PROPERTY_TYPES = [
        self::PROPERTY_STRING,
        self::PROPERTY_INTEGER,
        self::PROPERTY_BOOLEAN,
        self::PROPERTY_ENUM,
        self::PROPERTY_STRING_LIST,
    ];

    private const PROPERTY_RULE_KEYS = [
        'type',
        'required',
        'max_length',
        'min',
        'max',
        'values',
        'max_items',
        'max_item_length',
    ];

    /**
     * @param array<int, string> $surfaces
     * @param array<string, array<string, mixed>> $properties
     * @param array<int, string> $browserHosts
     */
    public function __construct(
        public string $key,
        public int $version,
        public array $surfaces,
        public string $sessionMode = self::SESSION_OPTIONAL,
        public array $properties = [],
        public bool $funnelEligible = false,
        public array $browserHosts = [],
    ) {
        if (! self::validIdentifier($key, 100)) {
            throw new InvalidArgumentException(
                'Reporting event key must be a non-empty lowercase identifier no longer than 100 characters.',
            );
        }

        if ($version < 1 || $version > 65535) {
            throw new InvalidArgumentException(
                "Reporting event [{$key}] version must be between 1 and 65535.",
            );
        }

        if (! in_array($sessionMode, self::SESSION_MODES, true)) {
            throw new InvalidArgumentException(
                "Reporting event [{$key}:{$version}] has unsupported session mode [{$sessionMode}].",
            );
        }

        if ($surfaces === []) {
            throw new InvalidArgumentException(
                "Reporting event [{$key}:{$version}] must allow at least one surface.",
            );
        }

        foreach ($surfaces as $surface) {
            if (! is_string($surface) || ! self::validIdentifier($surface, 80)) {
                throw new InvalidArgumentException(
                    "Reporting event [{$key}:{$version}] contains an invalid surface.",
                );
            }
        }

        foreach ($browserHosts as $browserHost) {
            if (! is_string($browserHost) || ! self::validHost($browserHost)) {
                throw new InvalidArgumentException(
                    "Reporting event [{$key}:{$version}] contains an invalid browser host.",
                );
            }
        }

        foreach ($properties as $propertyKey => $rules) {
            if (! is_string($propertyKey) || ! is_array($rules)) {
                throw new InvalidArgumentException(
                    "Reporting event [{$key}:{$version}] properties must map string keys to rule arrays.",
                );
            }

            $this->validatePropertyDefinition($propertyKey, $rules);
        }
    }

    public function allowsSurface(string $surface): bool
    {
        return in_array($surface, $this->surfaces, true);
    }

    public function allowsBrowserHost(string $host): bool
    {
        $host = rtrim(strtolower(trim($host)), '.');

        return in_array($host, $this->browserHosts, true);
    }

    /**
     * @param array<string, mixed> $rules
     */
    private function validatePropertyDefinition(string $propertyKey, array $rules): void
    {
        if (! self::validIdentifier($propertyKey, 80)) {
            throw new InvalidArgumentException(
                "Reporting event [{$this->key}:{$this->version}] contains invalid property key [{$propertyKey}].",
            );
        }

        $unknownRuleKeys = array_values(array_diff(
            array_keys($rules),
            self::PROPERTY_RULE_KEYS,
        ));

        if ($unknownRuleKeys !== []) {
            throw new InvalidArgumentException(sprintf(
                'Reporting event [%s:%d] property [%s] contains unsupported rule key(s): %s.',
                $this->key,
                $this->version,
                $propertyKey,
                implode(', ', $unknownRuleKeys),
            ));
        }

        $type = $rules['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::PROPERTY_TYPES, true)) {
            throw new InvalidArgumentException(
                "Reporting event [{$this->key}:{$this->version}] property [{$propertyKey}] has an unsupported type.",
            );
        }

        $allowedForType = match ($type) {
            self::PROPERTY_STRING => ['type', 'required', 'max_length'],
            self::PROPERTY_INTEGER => ['type', 'required', 'min', 'max'],
            self::PROPERTY_BOOLEAN => ['type', 'required'],
            self::PROPERTY_ENUM => ['type', 'required', 'values'],
            self::PROPERTY_STRING_LIST => ['type', 'required', 'max_items', 'max_item_length'],
        };

        $inapplicableRules = array_values(array_diff(array_keys($rules), $allowedForType));

        if ($inapplicableRules !== []) {
            throw new InvalidArgumentException(sprintf(
                'Reporting event [%s:%d] property [%s] uses rule(s) not valid for type [%s]: %s.',
                $this->key,
                $this->version,
                $propertyKey,
                $type,
                implode(', ', $inapplicableRules),
            ));
        }

        if (array_key_exists('required', $rules) && ! is_bool($rules['required'])) {
            throw new InvalidArgumentException(
                "Reporting event [{$this->key}:{$this->version}] property [{$propertyKey}] required must be boolean.",
            );
        }

        foreach (['max_length', 'max_items', 'max_item_length'] as $integerRule) {
            if (array_key_exists($integerRule, $rules)
                && (! is_int($rules[$integerRule]) || $rules[$integerRule] < 1)
            ) {
                throw new InvalidArgumentException(
                    "Reporting event [{$this->key}:{$this->version}] property [{$propertyKey}] {$integerRule} must be a positive integer.",
                );
            }
        }

        foreach (['min', 'max'] as $integerRule) {
            if (array_key_exists($integerRule, $rules) && ! is_int($rules[$integerRule])) {
                throw new InvalidArgumentException(
                    "Reporting event [{$this->key}:{$this->version}] property [{$propertyKey}] {$integerRule} must be an integer.",
                );
            }
        }

        if (isset($rules['min'], $rules['max']) && $rules['min'] > $rules['max']) {
            throw new InvalidArgumentException(
                "Reporting event [{$this->key}:{$this->version}] property [{$propertyKey}] min cannot exceed max.",
            );
        }

        if ($type === self::PROPERTY_ENUM) {
            $values = $rules['values'] ?? null;

            if (! is_array($values) || $values === [] || ! array_is_list($values)) {
                throw new InvalidArgumentException(
                    "Reporting event [{$this->key}:{$this->version}] enum property [{$propertyKey}] requires a non-empty values list.",
                );
            }

            foreach ($values as $value) {
                if (! is_string($value) || trim($value) === '') {
                    throw new InvalidArgumentException(
                        "Reporting event [{$this->key}:{$this->version}] enum property [{$propertyKey}] values must be non-empty strings.",
                    );
                }
            }
        } elseif (array_key_exists('values', $rules)) {
            throw new InvalidArgumentException(
                "Reporting event [{$this->key}:{$this->version}] property [{$propertyKey}] may use values only with enum type.",
            );
        }
    }

    private static function validIdentifier(string $value, int $maximumLength): bool
    {
        return $value !== ''
            && strlen($value) <= $maximumLength
            && preg_match('/^[a-z0-9][a-z0-9._-]*$/', $value) === 1;
    }

    private static function validHost(string $value): bool
    {
        return $value !== ''
            && $value === strtolower($value)
            && $value === rtrim($value, '.')
            && strlen($value) <= 255
            && ! str_contains($value, '..')
            && preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $value) === 1;
    }
}
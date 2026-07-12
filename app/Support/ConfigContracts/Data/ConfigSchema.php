<?php

namespace App\Support\ConfigContracts\Data;

use InvalidArgumentException;

class ConfigSchema
{
    private const KIND_SCALAR = 'scalar';
    private const KIND_OBJECT = 'object';
    private const KIND_LIST = 'list';
    private const KIND_MAP = 'map';
    private const KIND_ONE_OF = 'one_of';
    private const KIND_MIXED = 'mixed';

    /**
     * @param array<string, ConfigField> $fields
     * @param array<int, ConfigSchema> $options
     * @param array<int, mixed> $allowedValues
     * @param array<int, array<int, string>> $atLeastOne
     */
    private function __construct(
        private readonly string $kind,
        private readonly ?string $scalarType = null,
        private readonly bool $nullable = false,
        private readonly array $fields = [],
        private readonly bool $allowUnknown = false,
        private readonly ?ConfigSchema $items = null,
        private readonly array $options = [],
        private readonly array $allowedValues = [],
        private readonly array $atLeastOne = [],
    ) {}

    /**
     * @param array<int, mixed> $allowedValues
     */
    public static function string(bool $nullable = false, array $allowedValues = []): self
    {
        return self::scalar('string', $nullable, $allowedValues);
    }

    public static function integer(bool $nullable = false): self
    {
        return self::scalar('integer', $nullable);
    }

    public static function number(bool $nullable = false): self
    {
        return self::scalar('number', $nullable);
    }

    public static function boolean(bool $nullable = false): self
    {
        return self::scalar('boolean', $nullable);
    }

    /**
     * @param array<string, ConfigField> $fields
     */
    public static function object(
        array $fields,
        bool $allowUnknown = false,
        bool $nullable = false,
        array $atLeastOne = [],
    ): self
    {
        foreach ($fields as $key => $field) {
            if (! is_string($key) || ! $field instanceof ConfigField) {
                throw new InvalidArgumentException('Config object fields must map string keys to ConfigField instances.');
            }
        }

        foreach ($atLeastOne as $group) {
            if (! is_array($group) || $group === []) {
                throw new InvalidArgumentException('Config object atLeastOne groups must contain declared field names.');
            }

            foreach ($group as $key) {
                if (! is_string($key) || ! array_key_exists($key, $fields)) {
                    throw new InvalidArgumentException('Config object atLeastOne groups must contain declared field names.');
                }
            }
        }

        return new self(
            kind: self::KIND_OBJECT,
            nullable: $nullable,
            fields: $fields,
            allowUnknown: $allowUnknown,
            atLeastOne: $atLeastOne,
        );
    }

    public static function listOf(ConfigSchema $items, bool $nullable = false): self
    {
        return new self(kind: self::KIND_LIST, nullable: $nullable, items: $items);
    }

    public static function mapOf(ConfigSchema $items, bool $nullable = false): self
    {
        return new self(kind: self::KIND_MAP, nullable: $nullable, items: $items);
    }

    /**
     * @param array<int, ConfigSchema> $options
     */
    public static function oneOf(array $options, bool $nullable = false): self
    {
        if ($options === []) {
            throw new InvalidArgumentException('Config oneOf schemas require one or more ConfigSchema options.');
        }

        foreach ($options as $option) {
            if (! $option instanceof self) {
                throw new InvalidArgumentException('Config oneOf schemas require one or more ConfigSchema options.');
            }
        }

        return new self(kind: self::KIND_ONE_OF, nullable: $nullable, options: array_values($options));
    }

    public static function mixed(bool $nullable = true): self
    {
        return new self(kind: self::KIND_MIXED, nullable: $nullable);
    }

    /**
     * @return array<int, ConfigContractViolation>
     */
    public function validate(mixed $value, string $path = 'config'): array
    {
        if ($value === null) {
            return $this->nullable
                ? []
                : [$this->violation('null_not_allowed', $path, "[{$path}] may not be null.")];
        }

        return match ($this->kind) {
            self::KIND_SCALAR => $this->validateScalar($value, $path),
            self::KIND_OBJECT => $this->validateObject($value, $path),
            self::KIND_LIST => $this->validateList($value, $path),
            self::KIND_MAP => $this->validateMap($value, $path),
            self::KIND_ONE_OF => $this->validateOneOf($value, $path),
            self::KIND_MIXED => [],
            default => [$this->violation('schema_invalid', $path, "[{$path}] uses an unsupported schema kind.")],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'type' => $this->scalarType,
            'nullable' => $this->nullable,
            'allow_unknown' => $this->kind === self::KIND_OBJECT ? $this->allowUnknown : null,
            'at_least_one' => $this->kind === self::KIND_OBJECT && $this->atLeastOne !== []
                ? $this->atLeastOne
                : null,
            'allowed_values' => $this->allowedValues !== [] ? $this->allowedValues : null,
            'fields' => $this->fields !== []
                ? array_map(fn (ConfigField $field): array => $field->describe(), $this->fields)
                : null,
            'items' => $this->items?->describe(),
            'options' => $this->options !== []
                ? array_map(fn (self $option): array => $option->describe(), $this->options)
                : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    public function normalize(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this->kind) {
            self::KIND_OBJECT => $this->normalizeObject($value),
            self::KIND_LIST => is_array($value)
                ? array_values(array_map(fn (mixed $item): mixed => $this->items->normalize($item), $value))
                : $value,
            self::KIND_MAP => is_array($value)
                ? array_map(fn (mixed $item): mixed => $this->items->normalize($item), $value)
                : $value,
            self::KIND_ONE_OF => $this->normalizeOneOf($value),
            default => $value,
        };
    }

    /**
     * @param array<int, mixed> $allowedValues
     */
    private static function scalar(string $type, bool $nullable, array $allowedValues = []): self
    {
        return new self(
            kind: self::KIND_SCALAR,
            scalarType: $type,
            nullable: $nullable,
            allowedValues: array_values($allowedValues),
        );
    }

    /**
     * @return array<int, ConfigContractViolation>
     */
    private function validateScalar(mixed $value, string $path): array
    {
        $valid = match ($this->scalarType) {
            'string' => is_string($value) && trim($value) !== '',
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            default => false,
        };

        if (! $valid) {
            return [$this->violation(
                'type_invalid',
                $path,
                "[{$path}] must be a valid {$this->scalarType}.",
                ['expected_type' => $this->scalarType, 'actual_type' => get_debug_type($value)],
            )];
        }

        if ($this->allowedValues !== [] && ! in_array($value, $this->allowedValues, true)) {
            return [$this->violation(
                'value_not_allowed',
                $path,
                "[{$path}] contains an unsupported value.",
                ['allowed_values' => $this->allowedValues, 'actual_value' => $value],
            )];
        }

        return [];
    }

    /**
     * @return array<int, ConfigContractViolation>
     */
    private function validateObject(mixed $value, string $path): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            return [$this->typeViolation($path, 'object', $value)];
        }

        $violations = [];

        if (! $this->allowUnknown) {
            foreach (array_diff(array_keys($value), array_keys($this->fields)) as $unknownKey) {
                $unknownPath = $this->childPath($path, (string) $unknownKey);
                $violations[] = $this->violation(
                    'unknown_field',
                    $unknownPath,
                    "[{$unknownPath}] is not declared by the config contract.",
                );
            }
        }

        foreach ($this->atLeastOne as $group) {
            $present = array_filter(
                $group,
                fn (string $key): bool => array_key_exists($key, $value) && $value[$key] !== null,
            );

            if ($present === []) {
                $violations[] = $this->violation(
                    'required_field_group_missing',
                    $path,
                    sprintf('[%s] requires at least one of [%s].', $path, implode(', ', $group)),
                    ['fields' => $group],
                );
            }
        }

        foreach ($this->fields as $key => $field) {
            $fieldPath = $this->childPath($path, $key);

            if (! array_key_exists($key, $value)) {
                if ($field->required) {
                    $violations[] = $this->violation(
                        'required_field_missing',
                        $fieldPath,
                        "[{$fieldPath}] is required.",
                    );
                }

                continue;
            }

            array_push($violations, ...$field->schema->validate($value[$key], $fieldPath));
        }

        return $violations;
    }

    /**
     * @return array<int, ConfigContractViolation>
     */
    private function validateList(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [$this->typeViolation($path, 'list', $value)];
        }

        $violations = [];

        foreach ($value as $index => $item) {
            array_push($violations, ...$this->items->validate($item, $this->childPath($path, (string) $index)));
        }

        return $violations;
    }

    /**
     * @return array<int, ConfigContractViolation>
     */
    private function validateMap(mixed $value, string $path): array
    {
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            return [$this->typeViolation($path, 'map', $value)];
        }

        $violations = [];

        foreach ($value as $key => $item) {
            array_push($violations, ...$this->items->validate($item, $this->childPath($path, (string) $key)));
        }

        return $violations;
    }

    /**
     * @return array<int, ConfigContractViolation>
     */
    private function validateOneOf(mixed $value, string $path): array
    {
        foreach ($this->options as $option) {
            if ($option->validate($value, $path) === []) {
                return [];
            }
        }

        return [$this->violation(
            'one_of_invalid',
            $path,
            "[{$path}] does not match any supported config shape.",
            ['options' => array_map(fn (self $option): array => $option->describe(), $this->options)],
        )];
    }

    private function normalizeObject(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $normalized = [];

        foreach ($this->fields as $key => $field) {
            if (array_key_exists($key, $value)) {
                $normalized[$key] = $field->schema->normalize($value[$key]);

                continue;
            }

            if ($field->hasDefault) {
                $normalized[$key] = $field->schema->normalize($field->default);
            }
        }

        if ($this->allowUnknown) {
            foreach (array_diff_key($value, $this->fields) as $key => $item) {
                $normalized[$key] = $item;
            }
        }

        return $normalized;
    }

    private function normalizeOneOf(mixed $value): mixed
    {
        foreach ($this->options as $option) {
            if ($option->validate($value) === []) {
                return $option->normalize($value);
            }
        }

        return $value;
    }

    private function typeViolation(string $path, string $expected, mixed $value): ConfigContractViolation
    {
        return $this->violation(
            'type_invalid',
            $path,
            "[{$path}] must be a valid {$expected}.",
            ['expected_type' => $expected, 'actual_type' => get_debug_type($value)],
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function violation(string $code, string $path, string $message, array $meta = []): ConfigContractViolation
    {
        return new ConfigContractViolation($code, $path, $message, $meta);
    }

    private function childPath(string $path, string $key): string
    {
        return $path === '' ? $key : "{$path}.{$key}";
    }
}

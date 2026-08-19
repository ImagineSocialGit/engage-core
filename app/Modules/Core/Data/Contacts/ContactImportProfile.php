<?php

namespace App\Modules\Core\Data\Contacts;

use InvalidArgumentException;

final class ContactImportProfile
{
    private const KEY_PATTERN = '/^[a-z0-9]+(?:_[a-z0-9]+)*$/';

    /**
     * @param array<int, string> $filenameContains
     * @param array<string, string> $defaults
     * @param array<string, array<int, string>> $aliases
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $description,
        public readonly array $filenameContains,
        public readonly array $defaults,
        public readonly array $aliases,
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<int, string> $allowedFieldKeys
     */
    public static function fromArray(
        string $key,
        array $definition,
        array $allowedFieldKeys,
    ): self {
        if (! preg_match(self::KEY_PATTERN, $key)) {
            throw new InvalidArgumentException(
                'Contact import profile keys must use lowercase snake_case.',
            );
        }

        $unknown = array_values(array_diff(
            array_keys($definition),
            ['label', 'description', 'filename_contains', 'defaults', 'aliases'],
        ));

        if ($unknown !== []) {
            sort($unknown);

            throw new InvalidArgumentException(sprintf(
                'Contact import profile [%s] contains unknown field(s): %s.',
                $key,
                implode(', ', $unknown),
            ));
        }

        $label = self::requiredString($definition['label'] ?? null, "{$key}.label");
        $description = self::nullableString($definition['description'] ?? null, "{$key}.description");
        $filenameContains = self::stringList(
            $definition['filename_contains'] ?? [],
            "{$key}.filename_contains",
        );
        $defaults = self::defaults(
            $definition['defaults'] ?? [],
            $allowedFieldKeys,
            $key,
        );
        $aliases = self::aliases(
            $definition['aliases'] ?? [],
            $allowedFieldKeys,
            $key,
        );

        return new self(
            key: $key,
            label: $label,
            description: $description,
            filenameContains: $filenameContains,
            defaults: $defaults,
            aliases: $aliases,
        );
    }

    public function matchesFilename(string $filename): bool
    {
        if ($this->filenameContains === []) {
            return false;
        }

        $normalizedFilename = self::normalizeComparable(basename($filename));

        foreach ($this->filenameContains as $needle) {
            if (str_contains($normalizedFilename, self::normalizeComparable($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string> $allowedFieldKeys
     * @return array<string, string>
     */
    public function suggestedMapping(array $headers, array $allowedFieldKeys): array
    {
        $normalizedHeaders = [];

        foreach ($headers as $header) {
            if (! is_string($header) || trim($header) === '') {
                continue;
            }

            $normalizedHeaders[self::normalizeComparable($header)] ??= $header;
        }

        $allowed = array_fill_keys($allowedFieldKeys, true);
        $mapping = [];

        foreach ($this->aliases as $field => $aliases) {
            if (! isset($allowed[$field])) {
                continue;
            }

            foreach ($aliases as $alias) {
                $header = $normalizedHeaders[self::normalizeComparable($alias)] ?? null;

                if ($header !== null) {
                    $mapping[$field] = $header;
                    break;
                }
            }
        }

        return $mapping;
    }

    private static function normalizeComparable(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }

    private static function requiredString(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Contact import profile [{$path}] must be a non-empty string.",
            );
        }

        return trim($value);
    }

    private static function nullableString(mixed $value, string $path): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(
                "Contact import profile [{$path}] must be a string or null.",
            );
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException(
                "Contact import profile [{$path}] must be a list of strings.",
            );
        }

        $normalized = [];

        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(
                    "Contact import profile [{$path}] must contain only non-empty strings.",
                );
            }

            $normalized[] = trim($item);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int, string> $allowedFieldKeys
     * @return array<string, string>
     */
    private static function defaults(mixed $value, array $allowedFieldKeys, string $key): array
    {
        if (
            ! is_array($value)
            || ($value !== [] && array_is_list($value))
        ) {
            throw new InvalidArgumentException(
                "Contact import profile [{$key}.defaults] must be a keyed array.",
            );
        }

        $allowed = array_fill_keys($allowedFieldKeys, true);
        $defaults = [];

        foreach ($value as $field => $default) {
            if (! is_string($field) || ! isset($allowed[$field])) {
                throw new InvalidArgumentException(
                    "Contact import profile [{$key}] has an unknown default field [{$field}].",
                );
            }

            if ($field === 'email') {
                throw new InvalidArgumentException(
                    "Contact import profile [{$key}] may not supply a default email identity.",
                );
            }

            if (! is_string($default) || trim($default) === '') {
                throw new InvalidArgumentException(
                    "Contact import profile [{$key}.defaults.{$field}] must be a non-empty string.",
                );
            }

            $defaults[$field] = trim($default);
        }

        return $defaults;
    }

    /**
     * @param array<int, string> $allowedFieldKeys
     * @return array<string, array<int, string>>
     */
    private static function aliases(mixed $value, array $allowedFieldKeys, string $key): array
    {
        if (
            ! is_array($value)
            || ($value !== [] && array_is_list($value))
        ) {
            throw new InvalidArgumentException(
                "Contact import profile [{$key}.aliases] must be a keyed array.",
            );
        }

        $allowed = array_fill_keys($allowedFieldKeys, true);
        $aliases = [];

        foreach ($value as $field => $fieldAliases) {
            if (! is_string($field) || ! isset($allowed[$field])) {
                throw new InvalidArgumentException(
                    "Contact import profile [{$key}] has an unknown alias field [{$field}].",
                );
            }

            $aliases[$field] = self::stringList(
                $fieldAliases,
                "{$key}.aliases.{$field}",
            );
        }

        return $aliases;
    }
}
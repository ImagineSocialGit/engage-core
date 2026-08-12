<?php

namespace App\Modules\Forms\Services;

use InvalidArgumentException;

final class FormSchemaNormalizer
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public const FIELD_TYPES = [
        'text',
        'email',
        'tel',
        'url',
        'number',
        'textarea',
        'select',
        'radio',
        'checkbox',
        'checkboxes',
        'boolean',
        'date',
        'datetime',
        'hidden',
    ];

    private const OPTION_FIELD_TYPES = [
        'select',
        'radio',
        'checkboxes',
    ];

    /**
     * @return array<string, mixed>
     */
    public function normalize(mixed $value, string $context = 'Form schema'): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("{$context} must be an array.");
        }

        $sections = $value['sections'] ?? null;

        if (! is_array($sections) || ! array_is_list($sections) || $sections === []) {
            throw new InvalidArgumentException(
                "{$context}.sections must be a non-empty list.",
            );
        }

        $sectionKeys = [];
        $fieldKeys = [];

        foreach ($sections as $sectionIndex => $section) {
            if (! is_array($section)) {
                throw new InvalidArgumentException(
                    "{$context}.sections.{$sectionIndex} must be an array.",
                );
            }

            $sectionKey = $this->normalizeKey(
                $section['key'] ?? null,
                "{$context} section {$sectionIndex} key",
            );

            if (isset($sectionKeys[$sectionKey])) {
                throw new InvalidArgumentException(
                    "{$context} contains duplicate section key [{$sectionKey}].",
                );
            }

            $sectionKeys[$sectionKey] = true;
            $value['sections'][$sectionIndex]['key'] = $sectionKey;

            if (array_key_exists('label', $section)) {
                $value['sections'][$sectionIndex]['label'] = $this->nullableString(
                    $section['label'],
                    "{$context} section [{$sectionKey}] label",
                    255,
                );
            }

            $fields = $section['fields'] ?? null;

            if (! is_array($fields) || ! array_is_list($fields) || $fields === []) {
                throw new InvalidArgumentException(
                    "{$context} section [{$sectionKey}] fields must be a non-empty list.",
                );
            }

            foreach ($fields as $fieldIndex => $field) {
                if (! is_array($field)) {
                    throw new InvalidArgumentException(
                        "{$context} section [{$sectionKey}] field {$fieldIndex} must be an array.",
                    );
                }

                $fieldKey = $this->normalizeKey(
                    $field['key'] ?? null,
                    "{$context} field {$fieldIndex} key",
                );

                if (isset($fieldKeys[$fieldKey])) {
                    throw new InvalidArgumentException(
                        "{$context} contains duplicate field key [{$fieldKey}].",
                    );
                }

                $fieldKeys[$fieldKey] = true;
                $fieldType = strtolower($this->requiredString(
                    $field['type'] ?? null,
                    "{$context} field [{$fieldKey}] type",
                    50,
                ));

                if (! in_array($fieldType, self::FIELD_TYPES, true)) {
                    throw new InvalidArgumentException(sprintf(
                        '%s field [%s] type [%s] is unsupported. Allowed field types: %s.',
                        $context,
                        $fieldKey,
                        $fieldType,
                        implode(', ', self::FIELD_TYPES),
                    ));
                }

                $fieldLabel = $this->requiredString(
                    $field['label'] ?? null,
                    "{$context} field [{$fieldKey}] label",
                    255,
                );

                if (array_key_exists('required', $field) && ! is_bool($field['required'])) {
                    throw new InvalidArgumentException(
                        "{$context} field [{$fieldKey}] required must be a boolean.",
                    );
                }

                $normalizedField = $field;
                $normalizedField['key'] = $fieldKey;
                $normalizedField['label'] = $fieldLabel;
                $normalizedField['type'] = $fieldType;
                $normalizedField['required'] = (bool) ($field['required'] ?? false);

                if (array_key_exists('options', $field)) {
                    $normalizedField['options'] = $this->normalizeOptions(
                        value: $field['options'],
                        context: "{$context} field [{$fieldKey}] options",
                    );
                }

                if (in_array($fieldType, self::OPTION_FIELD_TYPES, true)
                    && ($normalizedField['options'] ?? []) === []
                ) {
                    throw new InvalidArgumentException(
                        "{$context} field [{$fieldKey}] type [{$fieldType}] requires at least one option.",
                    );
                }

                $value['sections'][$sectionIndex]['fields'][$fieldIndex] = $normalizedField;
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<int, array<string, mixed>>
     */
    public function fields(array $schema, string $context = 'Form schema'): array
    {
        $schema = $this->normalize($schema, $context);
        $resolved = [];
        $sortOrder = 0;

        foreach ($schema['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $resolved[] = [
                    ...$field,
                    'section_key' => $section['key'],
                    'section_label' => $section['label'] ?? null,
                    'sort_order' => $sortOrder++,
                ];
            }
        }

        return $resolved;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function normalizeOptions(mixed $value, string $context): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("{$context} must be a list.");
        }

        $options = [];
        $optionValues = [];

        foreach ($value as $index => $option) {
            if (! is_array($option)) {
                throw new InvalidArgumentException(
                    "{$context}.{$index} must be an array.",
                );
            }

            $optionValue = $this->requiredString(
                $option['value'] ?? null,
                "{$context}.{$index}.value",
                255,
            );

            if (isset($optionValues[$optionValue])) {
                throw new InvalidArgumentException(
                    "{$context} contains duplicate option value [{$optionValue}].",
                );
            }

            $optionValues[$optionValue] = true;

            $options[] = [
                'value' => $optionValue,
                'label' => $this->requiredString(
                    $option['label'] ?? null,
                    "{$context}.{$index}.label",
                    255,
                ),
            ];
        }

        return $options;
    }

    private function normalizeKey(mixed $value, string $label): string
    {
        $value = $this->requiredString($value, $label, 150);

        if (preg_match(self::KEY_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(
                "{$label} [{$value}] must use lowercase snake_case and begin with a letter.",
            );
        }

        return $value;
    }

    private function requiredString(
        mixed $value,
        string $label,
        int $maximumLength,
    ): string {
        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string.");
        }

        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("{$label} cannot be empty.");
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "{$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function nullableString(
        mixed $value,
        string $label,
        int $maximumLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$label} must be a string or null.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "{$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }
}
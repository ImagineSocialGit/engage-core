<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\NormalizedFormSubmission;
use App\Modules\Forms\Data\NormalizedFormSubmissionValue;
use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Exceptions\FormSubmissionValidationException;
use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use InvalidArgumentException;
use Throwable;

final class FormSubmissionValidator
{
    public function __construct(
        private readonly ValidationFactory $validation,
    ) {}

    public function validateConfiguration(PublishedForm $form): void
    {
        $this->authoredRules($form);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function validate(PublishedForm $form, array $input): NormalizedFormSubmission
    {
        $errors = [];
        $rules = $this->authoredRules($form);
        $knownKeys = array_fill_keys($form->fieldKeys(), true);
        $unknownKeys = [];

        foreach (array_keys($input) as $key) {
            if (! is_string($key) || ! isset($knownKeys[$key])) {
                $unknownKeys[] = (string) $key;
            }
        }

        if ($unknownKeys !== []) {
            sort($unknownKeys, SORT_STRING);
            $errors['_submission'][] = sprintf(
                'Unknown form field keys: %s.',
                implode(', ', $unknownKeys),
            );
        }

        $payload = [];
        $values = [];

        foreach ($form->fields as $field) {
            $key = (string) $field['key'];
            $required = (bool) ($field['required'] ?? false);

            if (! array_key_exists($key, $input)) {
                if ($required) {
                    $errors[$key][] = 'This field is required.';
                }

                continue;
            }

            try {
                $value = $this->normalizeFieldValue($field, $input[$key]);
            } catch (InvalidArgumentException $exception) {
                $errors[$key][] = $exception->getMessage();

                continue;
            }

            if ($value === null) {
                if ($required) {
                    $errors[$key][] = 'This field is required.';
                }

                continue;
            }

            if ($required && is_array($value) && $value === []) {
                $errors[$key][] = 'This field is required.';

                continue;
            }

            $payload[$key] = $value;
            $values[] = new NormalizedFormSubmissionValue(
                fieldKey: $key,
                fieldLabel: (string) $field['label'],
                fieldType: (string) $field['type'],
                value: $value,
                sortOrder: (int) ($field['sort_order'] ?? 0),
            );
        }

        if ($rules !== []) {
            $validator = $this->validation->make($payload, $rules);

            if ($validator->fails()) {
                foreach ($validator->errors()->toArray() as $key => $messages) {
                    foreach ($messages as $message) {
                        $errors[$key][] = $message;
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new FormSubmissionValidationException($errors);
        }

        return new NormalizedFormSubmission(
            payload: $payload,
            values: $values,
        );
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function normalizeFieldValue(array $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = (string) $field['type'];

        return match ($type) {
            'text', 'tel', 'textarea', 'hidden' => $this->normalizeString($value),
            'email' => $this->normalizeEmail($value),
            'url' => $this->normalizeUrl($value),
            'number' => $this->normalizeNumber($value),
            'select', 'radio' => $this->normalizeOption($field, $value),
            'checkboxes' => $this->normalizeOptions($field, $value),
            'checkbox', 'boolean' => $this->normalizeBoolean($value),
            'date' => $this->normalizeDate($value),
            'datetime' => $this->normalizeDateTime($value),
            default => throw new InvalidArgumentException("Field type [{$type}] is unsupported."),
        };
    }

    private function normalizeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('The value must be a string.');
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 65535) {
            throw new InvalidArgumentException(
                'The value cannot exceed 65535 characters.',
            );
        }

        return $value;
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $value = $this->normalizeString($value);

        if ($value === null) {
            return null;
        }

        $value = strtolower($value);

        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The value must be a valid email address.');
        }

        return $value;
    }

    private function normalizeUrl(mixed $value): ?string
    {
        $value = $this->normalizeString($value);

        if ($value === null) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('The value must be a valid URL.');
        }

        return $value;
    }

    private function normalizeNumber(mixed $value): int|float
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw new InvalidArgumentException('The value must be a number.');
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new InvalidArgumentException('The value must be a finite number.');
        }

        $number = trim((string) $value);

        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/', $number) !== 1) {
            throw new InvalidArgumentException('The value must be a decimal number.');
        }

        $unsigned = ltrim($number, '+-');
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer !== '' ? $integer : '0';
        $fraction = rtrim($fraction, '0');

        if (strlen($integer) > 12 || strlen($fraction) > 4) {
            throw new InvalidArgumentException(
                'The value must fit within 12 integer digits and 4 decimal places.',
            );
        }

        if ($fraction === '') {
            return (int) $number;
        }

        return (float) $number;
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function normalizeOption(array $field, mixed $value): ?string
    {
        $value = $this->normalizeString($value);

        if ($value === null) {
            return null;
        }

        if (! in_array($value, $this->allowedOptionValues($field), true)) {
            throw new InvalidArgumentException('The selected option is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, string>
     */
    private function normalizeOptions(array $field, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('The value must be a list of selected options.');
        }

        $allowed = $this->allowedOptionValues($field);
        $normalized = [];

        foreach ($value as $selected) {
            if (! is_string($selected)) {
                throw new InvalidArgumentException('Each selected option must be a string.');
            }

            $selected = trim($selected);

            if (! in_array($selected, $allowed, true)) {
                throw new InvalidArgumentException('One or more selected options are invalid.');
            }

            if (! in_array($selected, $normalized, true)) {
                $normalized[] = $selected;
            }
        }

        if (mb_strlen(implode(', ', $normalized)) > 65535) {
            throw new InvalidArgumentException(
                'The selected option values cannot exceed 65535 characters in total.',
            );
        }

        return $normalized;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true', 'yes' => true,
                '0', 'false', 'no' => false,
                default => throw new InvalidArgumentException('The value must be boolean.'),
            };
        }

        throw new InvalidArgumentException('The value must be boolean.');
    }

    private function normalizeDate(mixed $value): ?string
    {
        $value = $this->normalizeString($value);

        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if (! $date instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
            || $value < '1000-01-01'
            || $value > '9999-12-31'
        ) {
            throw new InvalidArgumentException(
                'The value must use YYYY-MM-DD date format between 1000-01-01 and 9999-12-31.',
            );
        }

        return $value;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        $value = $this->normalizeString($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw new InvalidArgumentException(
                'The value must be an ISO-8601 datetime with an explicit UTC offset.',
            );
        }

        try {
            $dateTime = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'The value must be a valid ISO-8601 datetime.',
                previous: $exception,
            );
        }

        $dateTime = $dateTime->setTimezone(new DateTimeZone('UTC'));

        $minimum = new DateTimeImmutable('1970-01-01T00:00:01+00:00');
        $maximum = new DateTimeImmutable('2038-01-19T03:14:07+00:00');

        if ($dateTime < $minimum || $dateTime > $maximum) {
            throw new InvalidArgumentException(
                'The value must fall within the supported UTC timestamp range.',
            );
        }

        return $dateTime->format('Y-m-d\TH:i:sP');
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, string>
     */
    private function allowedOptionValues(array $field): array
    {
        return array_values(array_map(
            static fn (array $option): string => (string) $option['value'],
            $field['options'] ?? [],
        ));
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    private function authoredRules(PublishedForm $form): array
    {
        $resolved = [];
        $knownFields = array_fill_keys($form->fieldKeys(), true);

        foreach ($form->rules as $key => $rules) {
            if (! is_string($key) || trim($key) === '') {
                throw new DomainException(
                    "Published form [{$form->key}] contains an invalid submission rule key.",
                );
            }

            $fieldKey = str_ends_with($key, '.*') ? substr($key, 0, -2) : $key;

            if (! isset($knownFields[$fieldKey])) {
                throw new DomainException(
                    "Published form [{$form->key}] submission rules reference unknown field [{$key}].",
                );
            }

            if (is_string($rules) && trim($rules) !== '') {
                $resolved[$key] = $rules;

                continue;
            }

            if (is_array($rules)
                && array_is_list($rules)
                && collect($rules)->every(
                    static fn (mixed $rule): bool => is_string($rule) && trim($rule) !== '',
                )
            ) {
                $resolved[$key] = array_values($rules);

                continue;
            }

            throw new DomainException(
                "Published form [{$form->key}] submission rules for [{$key}] must be a rule string or list of rule strings.",
            );
        }

        return $resolved;
    }
}
<?php

namespace App\Modules\InboundMessaging\Services\Email;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class InboundEmailContactExtractor
{
    public const VERSION = 1;

    public const SOURCE_NONE = 'none';
    public const SOURCE_SENDER_EMAIL = 'sender_email';
    public const SOURCE_REPLY_TO_EMAIL = 'reply_to_email';
    public const SOURCE_SUBJECT = 'subject';
    public const SOURCE_SUBJECT_AFTER_LABEL = 'subject_after_label';
    public const SOURCE_BODY_AFTER_LABEL = 'body_after_label';

    /**
     * @return array<int, string>
     */
    public function targetKeys(): array
    {
        return [
            'email',
            'first_name',
            'last_name',
            'name',
            'phone',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function targetLabels(): array
    {
        return [
            'email' => 'Email',
            'first_name' => 'First name',
            'last_name' => 'Last name',
            'name' => 'Full name',
            'phone' => 'Phone',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function sourceOptions(string $target): array
    {
        $options = [
            self::SOURCE_NONE => 'Do not extract',
            self::SOURCE_SUBJECT => 'Entire subject',
            self::SOURCE_SUBJECT_AFTER_LABEL => 'Subject after a label',
            self::SOURCE_BODY_AFTER_LABEL => 'Body after a label',
        ];

        if ($target === 'email') {
            $options = [
                self::SOURCE_NONE => 'Do not extract',
                self::SOURCE_SENDER_EMAIL => 'Sender email address',
                self::SOURCE_REPLY_TO_EMAIL => 'Reply-To email address',
                self::SOURCE_SUBJECT => 'Entire subject',
                self::SOURCE_SUBJECT_AFTER_LABEL => 'Subject after a label',
                self::SOURCE_BODY_AFTER_LABEL => 'Body after a label',
            ];
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultDefinition(): array
    {
        return [
            'version' => self::VERSION,
            'fields' => [
                'email' => [
                    'source' => self::SOURCE_REPLY_TO_EMAIL,
                    'label' => null,
                ],
            ],
            'required_fields' => ['email'],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function normalizeDefinition(array $definition): array
    {
        $fields = [];

        foreach ($this->targetKeys() as $target) {
            $field = Arr::get($definition, "fields.{$target}");

            if (! is_array($field)) {
                continue;
            }

            $source = trim((string) ($field['source'] ?? ''));

            if ($source === '' || $source === self::SOURCE_NONE) {
                continue;
            }

            $label = $this->nullableString($field['label'] ?? null);

            $fields[$target] = [
                'source' => $source,
                'label' => $label,
            ];
        }

        $required = collect($definition['required_fields'] ?? [])
            ->filter(fn (mixed $field): bool =>
                is_string($field)
                && in_array($field, $this->targetKeys(), true)
            )
            ->map(fn (string $field): string => trim($field))
            ->push('email')
            ->unique()
            ->filter(fn (string $field): bool => isset($fields[$field]))
            ->values()
            ->all();

        if (isset($fields['email']) && ! in_array('email', $required, true)) {
            array_unshift($required, 'email');
        }

        return [
            'version' => self::VERSION,
            'fields' => $fields,
            'required_fields' => $required,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, array<int, string>>
     */
    public function validationErrors(array $definition): array
    {
        $definition = $this->normalizeDefinition($definition);
        $errors = [];

        $email = $definition['fields']['email'] ?? null;

        if (! is_array($email)) {
            $errors['fields.email.source'][] =
                'Choose where the Contact email address should come from.';
        }

        foreach ($definition['fields'] as $target => $field) {
            $source = $field['source'] ?? null;

            if (! is_string($source)
                || ! array_key_exists($source, $this->sourceOptions((string) $target))
                || $source === self::SOURCE_NONE
            ) {
                $errors["fields.{$target}.source"][] =
                    'Choose a supported extraction source.';

                continue;
            }

            if (in_array($source, [
                self::SOURCE_SUBJECT_AFTER_LABEL,
                self::SOURCE_BODY_AFTER_LABEL,
            ], true) && $this->nullableString($field['label'] ?? null) === null) {
                $errors["fields.{$target}.label"][] =
                    'Enter the label that appears immediately before this value.';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $definition
     * @return array{
     *     ok: bool,
     *     values: array<string, string>,
     *     errors: array<int, string>
     * }
     */
    public function extract(array $source, array $definition): array
    {
        $definition = $this->normalizeDefinition($definition);
        $definitionErrors = $this->validationErrors($definition);

        if ($definitionErrors !== []) {
            return [
                'ok' => false,
                'values' => [],
                'errors' => collect($definitionErrors)
                    ->flatten()
                    ->map(fn (mixed $error): string => (string) $error)
                    ->values()
                    ->all(),
            ];
        }

        $values = [];

        foreach ($definition['fields'] as $target => $field) {
            $value = $this->valueFor(
                target: (string) $target,
                source: (string) $field['source'],
                label: $this->nullableString($field['label'] ?? null),
                input: $source,
            );

            if ($value !== null) {
                $values[(string) $target] = $value;
            }
        }

        $errors = [];

        foreach ($definition['required_fields'] as $target) {
            if (! isset($values[$target]) || trim($values[$target]) === '') {
                $errors[] = ($this->targetLabels()[$target] ?? Str::headline($target))
                    .' could not be extracted.';
            }
        }

        if (isset($values['email'])
            && filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
        ) {
            $errors[] = 'The extracted Email value is not a valid email address.';
        }

        if ($errors !== []) {
            return [
                'ok' => false,
                'values' => $values,
                'errors' => $errors,
            ];
        }

        if (! isset($values['name'])) {
            $name = trim(implode(' ', array_filter([
                $values['first_name'] ?? null,
                $values['last_name'] ?? null,
            ])));

            if ($name !== '') {
                $values['name'] = $name;
            }
        }

        return [
            'ok' => true,
            'values' => $values,
            'errors' => [],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function definitionHash(array $definition): string
    {
        return hash('sha256', json_encode(
            $this->normalizeDefinition($definition),
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * @param array<string, mixed> $source
     */
    private function valueFor(
        string $target,
        string $source,
        ?string $label,
        array $input,
    ): ?string {
        $value = match ($source) {
            self::SOURCE_SENDER_EMAIL =>
                $this->nullableString($input['sender_email'] ?? null),
            self::SOURCE_REPLY_TO_EMAIL =>
                $this->nullableString($input['reply_to_email'] ?? null),
            self::SOURCE_SUBJECT =>
                $this->nullableString($input['subject'] ?? null),
            self::SOURCE_SUBJECT_AFTER_LABEL =>
                $this->afterLabel(
                    $this->nullableString($input['subject'] ?? null),
                    $label,
                ),
            self::SOURCE_BODY_AFTER_LABEL =>
                $this->afterLabel(
                    $this->nullableString($input['body'] ?? null),
                    $label,
                    multiline: true,
                ),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        if ($target === 'email') {
            return $this->emailAddress($value);
        }

        return mb_substr(trim($value), 0, 255);
    }

    private function afterLabel(
        ?string $value,
        ?string $label,
        bool $multiline = false,
    ): ?string {
        if ($value === null || $label === null) {
            return null;
        }

        $labelPattern = preg_quote(trim($label), '/');
        $pattern = '/(?:^|\R)\s*'.$labelPattern.'\s*(?::|-)\s*([^\r\n]+)\s*(?:\R|$)/iu';

        if (preg_match($pattern, $value, $matches) === 1) {
            return $this->nullableString($matches[1] ?? null);
        }

        if (! $multiline) {
            $inlinePattern = '/'.$labelPattern.'\s*(?::|-)\s*(.+)$/iu';

            return preg_match($inlinePattern, $value, $matches) === 1
                ? $this->nullableString($matches[1] ?? null)
                : null;
        }

        $lines = preg_split('/\R/u', $value) ?: [];

        foreach ($lines as $index => $line) {
            $lineLabel = preg_replace(
                '/\s*(?::|-)\s*$/u',
                '',
                trim((string) $line),
            ) ?? trim((string) $line);

            if (mb_strtolower($lineLabel) !== mb_strtolower(trim($label))) {
                continue;
            }

            for ($next = $index + 1; $next < count($lines); $next++) {
                $candidate = $this->nullableString($lines[$next] ?? null);

                if ($candidate !== null) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function emailAddress(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        if (preg_match('/<([^<>]+)>/', $value, $matches) === 1) {
            $value = trim((string) ($matches[1] ?? ''));
        }

        if (preg_match(
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            $value,
            $matches,
        ) === 1) {
            $value = trim((string) ($matches[0] ?? ''));
        }

        $value = mb_strtolower($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            ? $value
            : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
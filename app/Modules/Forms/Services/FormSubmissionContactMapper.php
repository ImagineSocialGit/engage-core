<?php

namespace App\Modules\Forms\Services;

use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Actions\Contacts\ResolveContactByEmailAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Forms\Data\PublishedForm;
use DomainException;

final class FormSubmissionContactMapper
{
    private const CONTACT_FIELDS = [
        'email',
        'first_name',
        'last_name',
        'name',
        'phone',
    ];

    private const CONTACT_FIELD_TYPES = [
        'email' => ['email'],
        'first_name' => ['text'],
        'last_name' => ['text'],
        'name' => ['text'],
        'phone' => ['tel'],
    ];

    public function __construct(
        private readonly ResolveContactByEmailAction $resolveContact,
        private readonly CreateOrUpdateContactAction $createOrUpdateContact,
    ) {}

    public function validateConfiguration(PublishedForm $form): void
    {
        $this->mapping($form);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(PublishedForm $form, array $payload): ?Contact
    {
        $mapping = $this->mapping($form);
        $contactConfig = $mapping['contact'];

        if ($contactConfig === null) {
            return null;
        }

        $fields = $contactConfig['fields'];
        $email = $this->contactValue($payload, $fields['email']);

        if ($email === null) {
            return null;
        }

        $firstName = $this->mappedValue($payload, $fields, 'first_name');
        $lastName = $this->mappedValue($payload, $fields, 'last_name');
        $explicitName = $this->mappedValue($payload, $fields, 'name');
        $phone = $this->mappedValue($payload, $fields, 'phone');
        $resolvedName = $explicitName ?? $this->composedName($firstName, $lastName);

        $this->resolveContact->handle(
            email: $email,
            name: $resolvedName,
            phone: $phone,
            source: $contactConfig['source'],
            subsource: $contactConfig['subsource'],
        );

        $contactData = ['email' => $email];

        foreach ([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'name' => $explicitName,
            'phone' => $phone,
        ] as $attribute => $value) {
            if ($value !== null) {
                $contactData[$attribute] = $value;
            }
        }

        $contact = $this->createOrUpdateContact->handle($contactData);

        foreach ($mapping['tags'] as $tagMapping) {
            $fieldKey = $tagMapping['field'];

            if (! array_key_exists($fieldKey, $payload)) {
                continue;
            }

            foreach ($this->selectedTokens($payload[$fieldKey]) as $token) {
                $tag = $tagMapping['values'][$token] ?? null;

                if ($tag === null) {
                    continue;
                }

                ContactTag::query()->firstOrCreate([
                    'contact_id' => $contact->getKey(),
                    'tag' => $tag,
                ]);
            }
        }

        return $contact;
    }

    /**
     * @return array{
     *     contact: array{fields: array<string, string>, source: string, subsource: ?string}|null,
     *     tags: array<int, array{field: string, values: array<string, string>}>
     * }
     */
    private function mapping(PublishedForm $form): array
    {
        $submission = $form->settings['submission'] ?? [];

        if (! is_array($submission)) {
            throw $this->configurationException(
                $form,
                'settings.submission must be an array.',
            );
        }

        $contact = $this->contactConfiguration(
            form: $form,
            value: $submission['contact'] ?? null,
        );
        $tags = $this->tagConfiguration(
            form: $form,
            value: $submission['tags'] ?? [],
        );

        if ($contact === null && $tags !== []) {
            throw $this->configurationException(
                $form,
                'settings.submission.tags requires settings.submission.contact.',
            );
        }

        return [
            'contact' => $contact,
            'tags' => $tags,
        ];
    }

    /**
     * @return array{fields: array<string, string>, source: string, subsource: ?string}|null
     */
    private function contactConfiguration(PublishedForm $form, mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw $this->configurationException(
                $form,
                'settings.submission.contact must be an array.',
            );
        }

        $unknownKeys = array_diff(array_keys($value), ['fields', 'source', 'subsource']);

        if ($unknownKeys !== []) {
            throw $this->configurationException(
                $form,
                'settings.submission.contact contains unknown keys: '.implode(', ', $unknownKeys).'.',
            );
        }

        $fields = $value['fields'] ?? null;

        if (! is_array($fields) || array_is_list($fields)) {
            throw $this->configurationException(
                $form,
                'settings.submission.contact.fields must be an attribute-to-field map.',
            );
        }

        $unknownAttributes = array_diff(array_keys($fields), self::CONTACT_FIELDS);

        if ($unknownAttributes !== []) {
            throw $this->configurationException(
                $form,
                'settings.submission.contact.fields contains unsupported Contact attributes: '
                    .implode(', ', $unknownAttributes).'.',
            );
        }

        if (! array_key_exists('email', $fields)) {
            throw $this->configurationException(
                $form,
                'settings.submission.contact.fields must map the Contact email attribute.',
            );
        }

        $normalizedFields = [];

        foreach ($fields as $attribute => $fieldKey) {
            if (! is_string($fieldKey) || trim($fieldKey) === '') {
                throw $this->configurationException(
                    $form,
                    "Contact attribute [{$attribute}] must map to a non-empty form field key.",
                );
            }

            $fieldKey = trim($fieldKey);
            $field = $form->field($fieldKey);

            if ($field === null) {
                throw $this->configurationException(
                    $form,
                    "Contact attribute [{$attribute}] maps unknown form field [{$fieldKey}].",
                );
            }

            if (! in_array($field['type'], self::CONTACT_FIELD_TYPES[$attribute], true)) {
                throw $this->configurationException(
                    $form,
                    "Contact attribute [{$attribute}] cannot map field [{$fieldKey}] of type [{$field['type']}].",
                );
            }

            $normalizedFields[$attribute] = $fieldKey;
        }

        return [
            'fields' => $normalizedFields,
            'source' => $this->requiredConfigurationString(
                form: $form,
                value: $value['source'] ?? 'forms',
                path: 'settings.submission.contact.source',
            ),
            'subsource' => $this->nullableConfigurationString(
                form: $form,
                value: $value['subsource'] ?? $form->key,
                path: 'settings.submission.contact.subsource',
            ),
        ];
    }

    /**
     * @return array<int, array{field: string, values: array<string, string>}>
     */
    private function tagConfiguration(PublishedForm $form, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->configurationException(
                $form,
                'settings.submission.tags must be a list.',
            );
        }

        $resolved = [];

        foreach ($value as $index => $mapping) {
            if (! is_array($mapping)) {
                throw $this->configurationException(
                    $form,
                    "settings.submission.tags.{$index} must be an array.",
                );
            }

            $unknownKeys = array_diff(array_keys($mapping), ['field', 'values']);

            if ($unknownKeys !== []) {
                throw $this->configurationException(
                    $form,
                    "settings.submission.tags.{$index} contains unknown keys: "
                        .implode(', ', $unknownKeys).'.',
                );
            }

            $fieldKey = $mapping['field'] ?? null;

            if (! is_string($fieldKey) || trim($fieldKey) === '') {
                throw $this->configurationException(
                    $form,
                    "settings.submission.tags.{$index}.field must be a form field key.",
                );
            }

            $fieldKey = trim($fieldKey);
            $field = $form->field($fieldKey);

            if ($field === null) {
                throw $this->configurationException(
                    $form,
                    "Tag mapping references unknown form field [{$fieldKey}].",
                );
            }

            $values = $mapping['values'] ?? null;

            if (! is_array($values) || array_is_list($values) || $values === []) {
                throw $this->configurationException(
                    $form,
                    "settings.submission.tags.{$index}.values must be a non-empty value-to-tag map.",
                );
            }

            $normalizedValues = [];

            foreach ($values as $token => $tag) {
                $token = (string) $token;

                if (! is_string($tag) || trim($tag) === '' || mb_strlen(trim($tag)) > 255) {
                    throw $this->configurationException(
                        $form,
                        "Tag mapping for field [{$fieldKey}] must use non-empty tag names no longer than 255 characters.",
                    );
                }

                $normalizedValues[$token] = trim($tag);
            }

            $this->validateTagTokens($form, $field, array_keys($normalizedValues));

            $resolved[] = [
                'field' => $fieldKey,
                'values' => $normalizedValues,
            ];
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  $tokens
     */
    private function validateTagTokens(PublishedForm $form, array $field, array $tokens): void
    {
        if (in_array($field['type'], ['select', 'radio', 'checkboxes'], true)) {
            $allowed = array_map(
                static fn (array $option): string => (string) $option['value'],
                $field['options'] ?? [],
            );

            foreach ($tokens as $token) {
                if (! in_array($token, $allowed, true)) {
                    throw $this->configurationException(
                        $form,
                        "Tag mapping for field [{$field['key']}] references invalid option [{$token}].",
                    );
                }
            }

            return;
        }

        if (in_array($field['type'], ['checkbox', 'boolean'], true)) {
            foreach ($tokens as $token) {
                if (! in_array($token, ['true', 'false'], true)) {
                    throw $this->configurationException(
                        $form,
                        "Boolean tag mapping for field [{$field['key']}] may use only true or false tokens.",
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function contactValue(array $payload, string $fieldKey): ?string
    {
        if (! array_key_exists($fieldKey, $payload) || ! is_string($payload[$fieldKey])) {
            return null;
        }

        $value = trim($payload[$fieldKey]);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 255) {
            throw new DomainException(
                "Contact-mapped form field [{$fieldKey}] cannot exceed 255 characters.",
            );
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $fields
     */
    private function mappedValue(array $payload, array $fields, string $attribute): ?string
    {
        $fieldKey = $fields[$attribute] ?? null;

        return $fieldKey !== null ? $this->contactValue($payload, $fieldKey) : null;
    }

    private function composedName(?string $firstName, ?string $lastName): ?string
    {
        $name = trim(implode(' ', array_filter(
            [$firstName, $lastName],
            static fn (?string $value): bool => $value !== null,
        )));

        return $name !== '' ? $name : null;
    }

    /**
     * @return array<int, string>
     */
    private function selectedTokens(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(
                static fn (mixed $selected): string => (string) $selected,
                $value,
            ));
        }

        if (is_bool($value)) {
            return [$value ? 'true' : 'false'];
        }

        if (is_string($value) || is_int($value) || is_float($value)) {
            return [(string) $value];
        }

        return [];
    }

    private function requiredConfigurationString(
        PublishedForm $form,
        mixed $value,
        string $path,
    ): string {
        $value = $this->nullableConfigurationString($form, $value, $path);

        if ($value === null) {
            throw $this->configurationException($form, "{$path} cannot be empty.");
        }

        return $value;
    }

    private function nullableConfigurationString(
        PublishedForm $form,
        mixed $value,
        string $path,
    ): ?string {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw $this->configurationException($form, "{$path} must be a string or null.");
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > 255) {
            throw $this->configurationException(
                $form,
                "{$path} cannot exceed 255 characters.",
            );
        }

        return $value;
    }

    private function configurationException(PublishedForm $form, string $message): DomainException
    {
        return new DomainException(
            "Published form [{$form->key}] has invalid submission mapping: {$message}",
        );
    }
}
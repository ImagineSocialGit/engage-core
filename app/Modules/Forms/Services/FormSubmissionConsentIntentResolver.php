<?php

namespace App\Modules\Forms\Services;

use App\Modules\Forms\Data\FormSubmissionConsentIntent;
use App\Modules\Forms\Data\PublishedForm;
use DomainException;

final class FormSubmissionConsentIntentResolver
{
    private const IDENTIFIER_PATTERN = '/^[a-z][a-z0-9_.-]*$/';

    /**
     * @return array<int, FormSubmissionConsentIntent>
     */
    public function resolve(PublishedForm $form): array
    {
        $submission = $form->settings['submission'] ?? [];

        if (! is_array($submission)) {
            throw $this->configurationException(
                $form,
                'settings.submission must be an array.',
            );
        }

        $configured = $submission['consents'] ?? [];

        if (! is_array($configured) || ! array_is_list($configured)) {
            throw $this->configurationException(
                $form,
                'settings.submission.consents must be a list.',
            );
        }

        if ($configured === []) {
            return [];
        }

        if (! is_array($submission['contact'] ?? null)) {
            throw $this->configurationException(
                $form,
                'settings.submission.consents requires settings.submission.contact.',
            );
        }

        $resolved = [];
        $seen = [];

        foreach ($configured as $index => $mapping) {
            if (! is_array($mapping)) {
                throw $this->configurationException(
                    $form,
                    "settings.submission.consents.{$index} must be an array.",
                );
            }

            $unknownKeys = array_diff(
                array_keys($mapping),
                ['field', 'channel', 'purpose'],
            );

            if ($unknownKeys !== []) {
                throw $this->configurationException(
                    $form,
                    "settings.submission.consents.{$index} contains unknown keys: "
                        .implode(', ', $unknownKeys).'.',
                );
            }

            $fieldKey = $this->requiredString(
                form: $form,
                value: $mapping['field'] ?? null,
                path: "settings.submission.consents.{$index}.field",
                maximumLength: 255,
            );
            $field = $form->field($fieldKey);

            if ($field === null) {
                throw $this->configurationException(
                    $form,
                    "settings.submission.consents.{$index} references unknown form field [{$fieldKey}].",
                );
            }

            if (! in_array($field['type'] ?? null, ['checkbox', 'boolean'], true)) {
                throw $this->configurationException(
                    $form,
                    "settings.submission.consents.{$index} field [{$fieldKey}] must be a checkbox or boolean field.",
                );
            }

            $channel = $this->identifier(
                form: $form,
                value: $mapping['channel'] ?? null,
                path: "settings.submission.consents.{$index}.channel",
            );
            $purpose = $this->identifier(
                form: $form,
                value: $mapping['purpose'] ?? null,
                path: "settings.submission.consents.{$index}.purpose",
            );

            $identity = implode('|', [$fieldKey, $channel, $purpose]);

            if (isset($seen[$identity])) {
                throw $this->configurationException(
                    $form,
                    "settings.submission.consents contains duplicate mapping [{$fieldKey}:{$channel}:{$purpose}].",
                );
            }

            $seen[$identity] = true;
            $resolved[] = new FormSubmissionConsentIntent(
                field: $fieldKey,
                channel: $channel,
                purpose: $purpose,
            );
        }

        return $resolved;
    }

    private function identifier(
        PublishedForm $form,
        mixed $value,
        string $path,
    ): string {
        $value = $this->requiredString(
            form: $form,
            value: $value,
            path: $path,
            maximumLength: 64,
        );
        $value = strtolower($value);

        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            throw $this->configurationException(
                $form,
                "{$path} must be a lowercase identifier beginning with a letter.",
            );
        }

        return $value;
    }

    private function requiredString(
        PublishedForm $form,
        mixed $value,
        string $path,
        int $maximumLength,
    ): string {
        if (! is_string($value) || trim($value) === '') {
            throw $this->configurationException(
                $form,
                "{$path} must be a non-empty string.",
            );
        }

        $value = trim($value);

        if (mb_strlen($value) > $maximumLength) {
            throw $this->configurationException(
                $form,
                "{$path} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function configurationException(
        PublishedForm $form,
        string $message,
    ): DomainException {
        return new DomainException(
            "Published form [{$form->key}] has invalid consent mapping: {$message}",
        );
    }
}
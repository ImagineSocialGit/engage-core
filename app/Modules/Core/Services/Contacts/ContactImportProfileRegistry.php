<?php

namespace App\Modules\Core\Services\Contacts;

use App\Modules\Core\Data\Contacts\ContactImportProfile;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use InvalidArgumentException;

final class ContactImportProfileRegistry
{
    public function __construct(
        private readonly ContactImportRegistry $imports,
    ) {}

    /**
     * @return array<string, ContactImportProfile>
     */
    public function all(): array
    {
        $configured = config('contact_imports.profiles', []);

        if (
            ! is_array($configured)
            || ($configured !== [] && array_is_list($configured))
        ) {
            throw new InvalidArgumentException(
                'Contact import profiles configuration must be a keyed array.',
            );
        }

        $profiles = [];
        $allowedFieldKeys = $this->allowedFieldKeys();

        foreach ($configured as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                throw new InvalidArgumentException(
                    'Each Contact import profile must use a string key and array definition.',
                );
            }

            $profiles[$key] = ContactImportProfile::fromArray(
                key: $key,
                definition: $definition,
                allowedFieldKeys: $allowedFieldKeys,
            );
        }

        return $profiles;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function get(string $key): ContactImportProfile
    {
        $profiles = $this->all();

        if (! isset($profiles[$key])) {
            throw new InvalidArgumentException(
                "Unknown Contact import profile [{$key}].",
            );
        }

        return $profiles[$key];
    }

    public function findByFilename(string $filename): ?ContactImportProfile
    {
        $matches = array_values(array_filter(
            $this->all(),
            static fn (ContactImportProfile $profile): bool => $profile->matchesFilename($filename),
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param array<int, string> $headers
     * @return array<string, string>
     */
    public function suggestedMapping(ContactImportProfile $profile, array $headers): array
    {
        return $profile->suggestedMapping(
            headers: $headers,
            allowedFieldKeys: $this->allowedFieldKeys(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function allowedFieldKeys(): array
    {
        return array_values(array_unique([
            ...$this->imports->fieldKeys(),
            'import_status',
        ]));
    }
}
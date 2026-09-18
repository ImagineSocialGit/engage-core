<?php

namespace App\Modules\Core\Support\Contacts;

final class ContactNameNormalizer
{
    private const NAME_FIELDS = [
        'first_name',
        'last_name',
        'name',
    ];

    /**
     * Normalize human-name fields without rewriting intentional mixed casing.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeFields(array $data): array
    {
        foreach (self::NAME_FIELDS as $field) {
            if (! array_key_exists($field, $data) || ! is_string($data[$field])) {
                continue;
            }

            $data[$field] = $this->normalize($data[$field]);
        }

        return $data;
    }

    public function normalize(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if ($value === '') {
            return '';
        }

        return preg_replace_callback(
            '/\p{L}+/u',
            static function (array $matches): string {
                $part = $matches[0];
                $lower = mb_strtolower($part, 'UTF-8');
                $upper = mb_strtoupper($part, 'UTF-8');

                if ($part !== $lower && $part !== $upper) {
                    return $part;
                }

                return mb_convert_case($part, MB_CASE_TITLE, 'UTF-8');
            },
            $value,
        ) ?? $value;
    }
}
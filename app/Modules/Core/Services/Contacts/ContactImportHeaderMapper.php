<?php

namespace App\Modules\Core\Services\Contacts;

use App\Modules\Core\Data\Contacts\ContactImportField;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;

final class ContactImportHeaderMapper
{
    /** @var array<string, array<int, string>> */
    private const ALIASES = [
        'first_name' => [
            'first',
            'firstname',
            'first name',
            'given name',
            'givenname',
            'forename',
            'fname',
        ],
        'last_name' => [
            'last',
            'lastname',
            'last name',
            'surname',
            'family name',
            'familyname',
            'lname',
        ],
        'name' => [
            'name',
            'full name',
            'fullname',
            'contact name',
        ],
        'email' => [
            'email',
            'e-mail',
            'email address',
            'emailaddress',
            'primary email',
            'primary email address',
        ],
        'phone' => [
            'phone',
            'phone number',
            'phonenumber',
            'telephone',
            'mobile',
            'mobile phone',
            'mobile number',
            'cell',
            'cell phone',
            'cell number',
        ],
        'birthday' => [
            'birthday',
            'birth date',
            'birthdate',
            'date of birth',
            'dob',
        ],
        'source' => [
            'source',
            'contact source',
            'lead source',
            'original source',
        ],
        'subsource' => [
            'subsource',
            'sub source',
            'source detail',
            'source details',
            'source type',
        ],
        'last_contacted_at' => [
            'last contacted',
            'last contacted at',
            'last contact',
            'last contact date',
        ],
        'last_activity_at' => [
            'last activity',
            'last activity at',
            'last activity date',
        ],
        'import_status' => [
            'status',
            'legacy status',
            'original status',
            'contact status',
            'crm status',
            'lead status',
            'customer status',
            'client status',
        ],
    ];

    public function __construct(
        private readonly ContactImportRegistry $imports,
    ) {}

    /**
     * @param array<int, string> $headers
     * @param array<string, string> $preferred
     * @return array<string, string>
     */
    public function suggest(array $headers, array $preferred = []): array
    {
        $headers = array_values(array_unique(array_filter(array_map(
            static fn (mixed $header): ?string => is_string($header) && trim($header) !== ''
                ? trim($header)
                : null,
            $headers,
        ))));

        $availableHeaders = array_fill_keys($headers, true);
        $mapping = [];
        $usedHeaders = [];

        foreach ($preferred as $fieldKey => $header) {
            if (! is_string($fieldKey)
                || ! is_string($header)
                || ! isset($availableHeaders[$header])
            ) {
                continue;
            }

            $mapping[$fieldKey] = $header;
            $usedHeaders[$header] = true;
        }

        foreach ($this->fields() as $field) {
            if (isset($mapping[$field->key])) {
                continue;
            }

            $header = $this->exactHeaderFor(
                field: $field,
                headers: $headers,
                usedHeaders: $usedHeaders,
            );

            if ($header === null) {
                continue;
            }

            $mapping[$field->key] = $header;
            $usedHeaders[$header] = true;
        }

        foreach ($this->fields() as $field) {
            if (isset($mapping[$field->key])) {
                continue;
            }

            $header = $this->fuzzyHeaderFor(
                field: $field,
                headers: $headers,
                usedHeaders: $usedHeaders,
            );

            if ($header === null) {
                continue;
            }

            $mapping[$field->key] = $header;
            $usedHeaders[$header] = true;
        }

        return $mapping;
    }

    /** @return array<int, \App\Modules\Core\Data\Contacts\ContactImportField> */
    private function fields(): array
    {
        return $this->imports->fields()->all();
    }

    /**
     * @param array<int, string> $headers
     * @param array<string, bool> $usedHeaders
     */
    private function exactHeaderFor(
        ContactImportField $field,
        array $headers,
        array $usedHeaders,
    ): ?string {
        $accepted = collect([
            $field->key,
            $field->label,
            ...(self::ALIASES[$field->key] ?? []),
        ])
            ->map(fn (string $value): string => $this->normalize($value))
            ->filter()
            ->unique()
            ->flip();

        $matches = array_values(array_filter(
            $headers,
            fn (string $header): bool => ! isset($usedHeaders[$header])
                && $accepted->has($this->normalize($header)),
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param array<int, string> $headers
     * @param array<string, bool> $usedHeaders
     */
    private function fuzzyHeaderFor(
        ContactImportField $field,
        array $headers,
        array $usedHeaders,
    ): ?string {
        $needles = collect([
            $field->key,
            $field->label,
            ...(self::ALIASES[$field->key] ?? []),
        ])
            ->map(fn (string $value): string => $this->normalize($value))
            ->filter(fn (string $value): bool => mb_strlen($value) >= 5)
            ->unique()
            ->values()
            ->all();

        if ($needles === []) {
            return null;
        }

        $scores = [];

        foreach ($headers as $header) {
            if (isset($usedHeaders[$header])) {
                continue;
            }

            $normalizedHeader = $this->normalize($header);

            if (mb_strlen($normalizedHeader) < 5) {
                continue;
            }

            $best = 0.0;

            foreach ($needles as $needle) {
                $best = max($best, $this->similarity($normalizedHeader, $needle));
            }

            if ($best >= 0.90) {
                $scores[$header] = $best;
            }
        }

        if ($scores === []) {
            return null;
        }

        arsort($scores, SORT_NUMERIC);
        $headersByScore = array_keys($scores);
        $bestHeader = $headersByScore[0];
        $bestScore = $scores[$bestHeader];
        $secondScore = isset($headersByScore[1])
            ? $scores[$headersByScore[1]]
            : 0.0;

        return ($bestScore - $secondScore) >= 0.08
            ? $bestHeader
            : null;
    }

    private function similarity(string $left, string $right): float
    {
        $maximum = max(strlen($left), strlen($right));

        if ($maximum === 0) {
            return 1.0;
        }

        return 1 - (levenshtein($left, $right) / $maximum);
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
    }
}
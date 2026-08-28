<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use App\Modules\Core\Support\Contacts\ContactFilterCriterionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CampaignAnnualTouchAudienceService
{
    public const MODE_ALL = 'all';
    public const MODE_CRITERIA = 'criteria';
    public const MODE_CONTACTS = 'contacts';

    public const MODES = [
        self::MODE_ALL,
        self::MODE_CRITERIA,
        self::MODE_CONTACTS,
    ];

    private const AUTHORABLE_KEYS = [
        'status',
        'relationship',
        'source',
        'subsource',
        'tag',
        'webinar_outcome',
    ];

    public function __construct(
        private readonly ContactFilterCriterionRegistry $criteria,
        private readonly ContactFilterResolver $resolver,
    ) {}

    /**
     * @return array{
     *     modes: array<string, string>,
     *     mode: string,
     *     criteria: array<int, array{key: string, label: string, help: string|null, options: array<int, array{value: string, label: string}>}>,
     *     selected: array<string, array<int, string>>,
     *     contact_ids: array<int, int>,
     *     selected_contacts: array<int, array{id: int, label: string}>,
     *     exclude_selected: array<string, array<int, string>>,
     *     exclude_contact_ids: array<int, int>,
     *     excluded_contacts: array<int, array{id: int, label: string}>,
     *     unavailable_criteria: array<int, array{key: string, values: array<int, string>}>,
     *     unavailable_exclude_criteria: array<int, array{key: string, values: array<int, string>}>,
     *     matching_count: int,
     *     summary: string
     * }
     */
    public function forProgram(?CampaignTouchProgram $program): array
    {
        $filter = $program instanceof CampaignTouchProgram
            ? $this->storedFilter($program)
            : $this->emptyFilter();

        $selected = $this->stringCriteria($filter['criteria'] ?? []);
        $exclude = is_array($filter['exclude'] ?? null)
            ? $filter['exclude']
            : [];
        $excludeSelected = $this->stringCriteria($exclude['criteria'] ?? []);
        $definitions = $this->definitions($selected, $excludeSelected);
        $visibleKeys = array_column($definitions, 'key');

        return [
            'modes' => [
                self::MODE_ALL => 'All eligible contacts',
                self::MODE_CRITERIA => 'Contacts matching conditions',
                self::MODE_CONTACTS => 'Specific contacts',
            ],
            'mode' => $this->mode($filter['mode'] ?? null),
            'criteria' => $definitions,
            'selected' => $selected,
            'contact_ids' => $this->integerValues($filter['contact_ids'] ?? []),
            'selected_contacts' => $this->contactOptions($filter['contact_ids'] ?? []),
            'exclude_selected' => $excludeSelected,
            'exclude_contact_ids' => $this->integerValues($exclude['contact_ids'] ?? []),
            'excluded_contacts' => $this->contactOptions($exclude['contact_ids'] ?? []),
            'unavailable_criteria' => $this->unavailableCriteria($selected, $visibleKeys),
            'unavailable_exclude_criteria' => $this->unavailableCriteria($excludeSelected, $visibleKeys),
            'matching_count' => $this->matchingCountForFilter($filter),
            'summary' => $this->summaryForFilter($filter),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     mode: string,
     *     criteria: array<string, array<int, string>>,
     *     contact_ids: array<int, int>,
     *     exclude: array{
     *         criteria: array<string, array<int, string>>,
     *         contact_ids: array<int, int>
     *     }
     * }
     */
    public function normalize(
        array $input,
        ?CampaignTouchProgram $program = null,
    ): array {
        $mode = $this->mode($input['mode'] ?? null);

        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages([
                'audience_mode' => 'Choose who should receive these annual messages.',
            ]);
        }

        $existing = $program instanceof CampaignTouchProgram
            ? $this->storedFilter($program)
            : $this->emptyFilter();
        $existingCriteria = $this->stringCriteria($existing['criteria'] ?? []);
        $existingExclude = is_array($existing['exclude'] ?? null)
            ? $existing['exclude']
            : [];
        $existingExcludeCriteria = $this->stringCriteria($existingExclude['criteria'] ?? []);

        $definitions = $this->definitions($existingCriteria, $existingExcludeCriteria);
        $definitionMap = [];

        foreach ($definitions as $definition) {
            $definitionMap[(string) $definition['key']] = $definition;
        }

        $criteria = $mode === self::MODE_CRITERIA
            ? $this->normalizeCriteria(
                input: is_array($input['criteria'] ?? null) ? $input['criteria'] : [],
                definitionMap: $definitionMap,
                existing: $existingCriteria,
                field: 'audience_criteria',
            )
            : [];

        $contactIds = $mode === self::MODE_CONTACTS
            ? $this->existingContactIds($input['contact_ids'] ?? [], 'audience_contact_ids')
            : [];

        if ($mode === self::MODE_CRITERIA && $criteria === []) {
            throw ValidationException::withMessages([
                'audience_criteria' => 'Choose at least one audience condition.',
            ]);
        }

        if ($mode === self::MODE_CONTACTS && $contactIds === []) {
            throw ValidationException::withMessages([
                'audience_contact_ids' => 'Choose at least one contact.',
            ]);
        }

        $excludeCriteria = $this->normalizeCriteria(
            input: is_array($input['exclude_criteria'] ?? null) ? $input['exclude_criteria'] : [],
            definitionMap: $definitionMap,
            existing: $existingExcludeCriteria,
            field: 'audience_exclude_criteria',
        );
        $excludeContactIds = $this->existingContactIds(
            $input['exclude_contact_ids'] ?? [],
            'audience_exclude_contact_ids',
        );

        return [
            'mode' => $mode,
            'criteria' => $criteria,
            'contact_ids' => $contactIds,
            'exclude' => [
                'criteria' => $excludeCriteria,
                'contact_ids' => $excludeContactIds,
            ],
        ];
    }

    /** @return Builder<Contact> */
    public function queryForProgram(CampaignTouchProgram $program): Builder
    {
        return $this->queryForFilter($this->storedFilter($program));
    }

    /** @param array<string, mixed> $filter @return Builder<Contact> */
    public function queryForFilter(array $filter): Builder
    {
        try {
            $mode = $this->mode($filter['mode'] ?? null);
            $criteria = $this->runtimeCriteria($this->stringCriteria($filter['criteria'] ?? []));
            $contactIds = $this->integerValues($filter['contact_ids'] ?? []);

            $query = match ($mode) {
                self::MODE_ALL => $this->resolver->query(['type' => 'all']),
                self::MODE_CRITERIA => $criteria !== null && $criteria !== []
                    ? $this->resolver->query([
                        'type' => 'criteria',
                        'criteria' => $criteria,
                    ])
                    : $this->emptyQuery(),
                self::MODE_CONTACTS => $contactIds !== []
                    ? $this->resolver->query([
                        'type' => 'contact_ids',
                        'contact_ids' => $contactIds,
                    ])
                    : $this->emptyQuery(),
                default => $this->emptyQuery(),
            };

            $exclude = is_array($filter['exclude'] ?? null)
                ? $filter['exclude']
                : [];
            $excludeCriteria = $this->stringCriteria($exclude['criteria'] ?? []);
            $availableCriterionKeys = array_column($this->criteria->definitions(), 'key');

            // Exclusions are disqualifiers: matching any configured exclusion
            // group removes the Contact. If a saved exclusion belongs to an
            // unavailable optional module, fail closed rather than risk a send
            // the operator explicitly intended to prevent.
            foreach ($excludeCriteria as $key => $values) {
                if (! in_array($key, $availableCriterionKeys, true)) {
                    return $this->emptyQuery();
                }

                $runtimeExclusion = $this->runtimeCriteria([$key => $values]);

                if ($runtimeExclusion === null) {
                    return $this->emptyQuery();
                }

                $excluded = $this->resolver
                    ->query([
                        'type' => 'criteria',
                        'criteria' => $runtimeExclusion,
                    ])
                    ->reorder()
                    ->select('contacts.id');

                $query->whereNotIn('contacts.id', $excluded);
            }

            $excludeContactIds = $this->integerValues($exclude['contact_ids'] ?? []);

            if ($excludeContactIds !== []) {
                $query->whereNotIn('contacts.id', $excludeContactIds);
            }

            return $query;
        } catch (InvalidArgumentException) {
            return $this->emptyQuery();
        }
    }

    public function matchingCountForFilter(array $filter): int
    {
        return $this->queryForFilter($filter)
            ->reorder()
            ->distinct()
            ->count('contacts.id');
    }

    public function summaryForProgram(CampaignTouchProgram $program): string
    {
        return $this->summaryForFilter($this->storedFilter($program));
    }

    /** @param array<string, mixed> $filter */
    public function summaryForFilter(array $filter): string
    {
        $mode = $this->mode($filter['mode'] ?? null);
        $exclude = is_array($filter['exclude'] ?? null)
            ? $filter['exclude']
            : [];
        $hasExclusions = $this->stringCriteria($exclude['criteria'] ?? []) !== []
            || $this->integerValues($exclude['contact_ids'] ?? []) !== [];

        $summary = match ($mode) {
            self::MODE_ALL => 'All eligible contacts',
            self::MODE_CONTACTS => count($this->integerValues($filter['contact_ids'] ?? [])).' selected contacts',
            self::MODE_CRITERIA => $this->criteriaSummary($this->stringCriteria($filter['criteria'] ?? [])),
            default => 'Audience unavailable',
        };

        return $hasExclusions ? $summary.' with exclusions' : $summary;
    }

    /**
     * Legacy `contact_status` rows remain readable while migrations/project
     * state move the durable contract to audience_filter.
     *
     * @return array<string, mixed>
     */
    private function storedFilter(CampaignTouchProgram $program): array
    {
        if (is_array($program->audience_filter) && $program->audience_filter !== []) {
            return $program->audience_filter;
        }

        if ($program->audience_type === CampaignTouchProgram::AUDIENCE_CONTACT_STATUS
            && is_string($program->audience_key)
            && trim($program->audience_key) !== ''
        ) {
            return [
                'mode' => self::MODE_CRITERIA,
                'criteria' => [
                    'status' => [trim($program->audience_key)],
                ],
                'contact_ids' => [],
                'exclude' => [
                    'criteria' => [],
                    'contact_ids' => [],
                ],
            ];
        }

        return $this->emptyFilter();
    }

    /** @return array<string, mixed> */
    private function emptyFilter(): array
    {
        return [
            'mode' => self::MODE_ALL,
            'criteria' => [],
            'contact_ids' => [],
            'exclude' => [
                'criteria' => [],
                'contact_ids' => [],
            ],
        ];
    }

    /**
     * @param array<string, array<int, string>> $selected
     * @param array<string, array<int, string>> $excludeSelected
     * @return array<int, array{key: string, label: string, help: string|null, options: array<int, array{value: string, label: string}>}>
     */
    private function definitions(array $selected, array $excludeSelected): array
    {
        $definitions = [];

        foreach ($this->criteria->definitions() as $definition) {
            $key = (string) ($definition['key'] ?? '');

            if (! in_array($key, self::AUTHORABLE_KEYS, true)) {
                continue;
            }

            $storedValues = array_values(array_unique(array_merge(
                $selected[$key] ?? [],
                $excludeSelected[$key] ?? [],
            )));

            if ($key === 'status') {
                $definitions[] = $this->statusDefinition($definition, $storedValues);

                continue;
            }

            $definitions[] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'help' => is_string($definition['help'] ?? null)
                    ? $definition['help']
                    : null,
                'options' => $this->optionsWithSelectedValues(
                    options: is_array($definition['options'] ?? null)
                        ? $definition['options']
                        : [],
                    selected: $storedValues,
                ),
            ];
        }

        return $definitions;
    }

    /**
     * The Workflow status criterion currently consumes DB ids. Annual-touch
     * configuration stores stable ContactStatus keys so Project State remains
     * portable and the Campaigns contract does not depend on Workflow.
     *
     * @param array<string, mixed> $definition
     * @param array<int, string> $selected
     * @return array{key: string, label: string, help: string|null, options: array<int, array{value: string, label: string}>}
     */
    private function statusDefinition(array $definition, array $selected): array
    {
        $statuses = ContactStatus::query()
            ->where(function (Builder $query) use ($selected): void {
                $query->where('is_active', true);

                if ($selected !== []) {
                    $query->orWhereIn('key', $selected);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['key', 'name', 'is_active']);

        return [
            'key' => 'status',
            'label' => (string) ($definition['label'] ?? 'Status'),
            'help' => is_string($definition['help'] ?? null)
                ? $definition['help']
                : null,
            'options' => $statuses
                ->map(fn (ContactStatus $status): array => [
                    'value' => (string) $status->key,
                    'label' => $status->name.($status->is_active ? '' : ' — currently inactive'),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, array<string, mixed>> $definitionMap
     * @param array<string, array<int, string>> $existing
     * @return array<string, array<int, string>>
     */
    private function normalizeCriteria(
        array $input,
        array $definitionMap,
        array $existing,
        string $field,
    ): array {
        $unsupported = array_values(array_diff(
            array_keys($input),
            array_keys($definitionMap),
        ));

        if ($unsupported !== []) {
            sort($unsupported);

            throw ValidationException::withMessages([
                $field => 'Unsupported audience condition(s): '.implode(', ', $unsupported).'.',
            ]);
        }

        $normalized = [];

        foreach ($definitionMap as $key => $definition) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $values = $this->stringValues($input[$key]);
            $allowed = array_column(
                is_array($definition['options'] ?? null) ? $definition['options'] : [],
                'value',
            );
            $invalid = array_values(array_diff($values, $allowed));

            if ($invalid !== []) {
                throw ValidationException::withMessages([
                    "{$field}.{$key}" => 'One or more selected values are not available for this condition.',
                ]);
            }

            if ($values !== []) {
                $normalized[$key] = $values;
            }
        }

        // Preserve saved criteria owned by an unavailable optional module so a
        // normal edit cannot silently broaden the audience.
        foreach ($existing as $key => $values) {
            if (array_key_exists($key, $definitionMap)) {
                continue;
            }

            $normalized[$key] = $values;
        }

        return $normalized;
    }

    /**
     * @param array<string, array<int, string>> $criteria
     * @return array<string, array<int, string>>|null
     */
    private function runtimeCriteria(array $criteria): ?array
    {
        if ($criteria === [] || ! array_key_exists('status', $criteria)) {
            return $criteria;
        }

        $statusKeys = $this->stringValues($criteria['status']);

        if ($statusKeys === []) {
            return null;
        }

        $normalizedKeys = array_values(array_unique(array_map(
            fn (string $key): string => $this->normalizeSegment($key),
            $statusKeys,
        )));

        $statuses = ContactStatus::query()
            ->where('is_active', true)
            ->whereIn('key', $normalizedKeys)
            ->get(['id', 'key'])
            ->keyBy('key');

        if ($statuses->count() !== count($normalizedKeys)) {
            return null;
        }

        $criteria['status'] = array_map(
            fn (string $key): string => (string) ((int) $statuses[$key]->getKey()),
            $normalizedKeys,
        );

        return $criteria;
    }

    /**
     * @param array<int, mixed> $options
     * @param array<int, string> $selected
     * @return array<int, array{value: string, label: string}>
     */
    private function optionsWithSelectedValues(array $options, array $selected): array
    {
        $resolved = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $value = is_string($option['value'] ?? null)
                ? trim($option['value'])
                : '';

            if ($value === '') {
                continue;
            }

            $resolved[$value] = [
                'value' => $value,
                'label' => is_string($option['label'] ?? null)
                    ? $option['label']
                    : $value,
            ];
        }

        foreach ($selected as $value) {
            if (array_key_exists($value, $resolved)) {
                continue;
            }

            $resolved[$value] = [
                'value' => $value,
                'label' => $value.' — currently unavailable',
            ];
        }

        return array_values($resolved);
    }

    /**
     * @param array<string, array<int, string>> $criteria
     * @param array<int, string> $visibleKeys
     * @return array<int, array{key: string, values: array<int, string>}>
     */
    private function unavailableCriteria(array $criteria, array $visibleKeys): array
    {
        $unavailable = [];

        foreach ($criteria as $key => $values) {
            if (in_array($key, $visibleKeys, true)) {
                continue;
            }

            $unavailable[] = [
                'key' => $key,
                'values' => $values,
            ];
        }

        return $unavailable;
    }

    /** @return array<string, array<int, string>> */
    private function stringCriteria(mixed $criteria): array
    {
        if (! is_array($criteria)) {
            return [];
        }

        $normalized = [];

        foreach ($criteria as $key => $values) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $values = $this->stringValues($values);

            if ($values !== []) {
                $normalized[trim($key)] = $values;
            }
        }

        return $normalized;
    }

    /** @return array<int, string> */
    private function stringValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null,
            $values,
        ))));
    }

    /** @return array<int, int> */
    private function integerValues(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null,
            $values,
        ), fn (?int $value): bool => $value !== null && $value > 0)));
    }

    /** @return array<int, int> */
    private function existingContactIds(mixed $values, string $field): array
    {
        $ids = $this->integerValues($values);

        if ($ids === []) {
            return [];
        }

        $existing = Contact::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $missing = array_values(array_diff($ids, $existing));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                $field => 'One or more selected contacts no longer exist.',
            ]);
        }

        return $ids;
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    private function contactOptions(mixed $values): array
    {
        $ids = $this->integerValues($values);

        if ($ids === []) {
            return [];
        }

        return Contact::query()
            ->whereIn('id', $ids)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('email')
            ->get(['id', 'first_name', 'last_name', 'name', 'email', 'phone'])
            ->map(function (Contact $contact): array {
                $name = trim((string) ($contact->name ?: trim($contact->first_name.' '.$contact->last_name)));
                $label = $name !== '' && $contact->email
                    ? $name.' — '.$contact->email
                    : ($name !== ''
                        ? $name
                        : ($contact->email ?: $contact->phone ?: 'Contact #'.$contact->getKey()));

                return [
                    'id' => (int) $contact->getKey(),
                    'label' => $label,
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string, array<int, string>> $criteria */
    private function criteriaSummary(array $criteria): string
    {
        $count = count($criteria);

        return $count === 1
            ? 'Contacts matching 1 condition group'
            : 'Contacts matching '.$count.' condition groups';
    }

    private function mode(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return self::MODE_ALL;
        }

        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    /** @return Builder<Contact> */
    private function emptyQuery(): Builder
    {
        return Contact::query()->whereRaw('1 = 0');
    }
}
<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Services\Contacts\ContactFilterResolver;
use App\Modules\Core\Support\Contacts\ContactFilterCriterionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CampaignEligibilityAuthoringService
{
    private const AUTHORABLE_KEYS = [
        'status',
        'relationship',
        'source',
        'subsource',
        'tag',
        'webinar_outcome',
    ];

    private const ADVANCED_KEYS = [
        'source',
        'subsource',
    ];

    public function __construct(
        private readonly ContactFilterCriterionRegistry $criteria,
        private readonly ContactFilterResolver $resolver,
    ) {}

    /**
     * @return array{
     *     criteria: array<int, array<string, mixed>>,
     *     selected: array<string, array<int, string>>,
     *     excluded: array<string, array<int, string>>,
     *     unavailable_criteria: array<int, array{key: string, values: array<int, string>}>,
     *     unavailable_exclusions: array<int, array{key: string, values: array<int, string>}>,
     *     matching_count: int,
     *     summary: string,
     *     enrollment_modes: array<string, string>,
     *     reentry_policies: array<string, string>,
     *     ineligible_behaviors: array<string, string>
     * }
     */
    public function forCampaign(Campaign $campaign): array
    {
        $selected = $campaign->eligibilityCriteria();
        $excluded = $campaign->eligibilityExclusions();
        $definitions = $this->definitions($selected, $excluded);
        $visibleKeys = array_column($definitions, 'key');

        return [
            'criteria' => $this->decorateDefinitions($definitions, $selected, $excluded),
            'selected' => $selected,
            'excluded' => $excluded,
            'unavailable_criteria' => $this->unavailableCriteria($selected, $visibleKeys),
            'unavailable_exclusions' => $this->unavailableCriteria($excluded, $visibleKeys),
            'matching_count' => $this->matchingCount($this->composeFilter($selected, $excluded)),
            'summary' => $this->audienceSummary(
                definitions: $definitions,
                selected: $selected,
                excluded: $excluded,
                automatic: $campaign->usesAutomaticEnrollment(),
            ),
            'enrollment_modes' => [
                Campaign::ENROLLMENT_MODE_MANUAL => 'Only when I add them',
                Campaign::ENROLLMENT_MODE_AUTOMATIC => 'Automatically when they match',
            ],
            'reentry_policies' => [
                Campaign::REENTRY_NEVER => 'No',
                Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN => 'Yes, when they match again',
            ],
            'ineligible_behaviors' => [
                Campaign::INELIGIBLE_CONTINUE => 'Keep them in the campaign',
                ...($campaign->usesRecurringAllocation() ? [] : [Campaign::INELIGIBLE_PAUSE => 'Pause their campaign']),
                Campaign::INELIGIBLE_CANCEL => 'Remove them from the campaign',
            ],
        ];
    }

    /**
     * Normalize the operator-editable include/exclude filter while preserving
     * saved criteria contributed by modules that are not currently available.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $exclusions
     * @return array<string, mixed>
     */
    public function normalizeForCampaign(
        Campaign $campaign,
        array $input,
        array $exclusions = [],
    ): array {
        $existing = $campaign->eligibilityCriteria();
        $existingExclusions = $campaign->eligibilityExclusions();
        $definitions = $this->definitions($existing, $existingExclusions);

        $definitionMap = [];

        foreach ($definitions as $definition) {
            $definitionMap[$definition['key']] = $definition;
        }

        $normalized = $this->normalizeDimension(
            field: 'eligibility_criteria',
            input: $input,
            existing: $existing,
            definitionMap: $definitionMap,
        );
        $normalizedExclusions = $this->normalizeDimension(
            field: 'eligibility_exclusions',
            input: $exclusions,
            existing: $existingExclusions,
            definitionMap: $definitionMap,
        );

        return $this->composeFilter($normalized, $normalizedExclusions);
    }

    /** @param array<string, mixed> $filter */
    public function matchingCount(array $filter): int
    {
        return $this->queryForFilter($filter)->count();
    }

    /** @return Builder<Contact> */
    public function matchingQuery(Campaign $campaign): Builder
    {
        return $this->queryForFilter(
            is_array($campaign->eligibility_filter)
                ? $campaign->eligibility_filter
                : [],
        );
    }

    /**
     * @param array<string, mixed> $filter
     * @return Builder<Contact>
     */
    private function queryForFilter(array $filter): Builder
    {
        [$criteria, $exclusions] = $this->splitFilter($filter);
        $runtimeCriteria = $this->runtimeCriteria($criteria);
        $runtimeExclusions = $this->runtimeCriteria($exclusions);

        if ($runtimeCriteria === null || $runtimeCriteria === []) {
            return Contact::query()->whereRaw('1 = 0');
        }

        // An unavailable/invalid exclusion must fail closed so a lead that was
        // intentionally excluded is never silently allowed back into outreach.
        if ($runtimeExclusions === null) {
            return Contact::query()->whereRaw('1 = 0');
        }

        try {
            return $this->resolver->query([
                'type' => 'criteria',
                'criteria' => $runtimeCriteria,
                'exclude_criteria' => $runtimeExclusions,
            ]);
        } catch (InvalidArgumentException) {
            return Contact::query()->whereRaw('1 = 0');
        }
    }

    /**
     * @param array<string, array<int, string>> $selected
     * @param array<string, array<int, string>> $excluded
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     help: string|null,
     *     options: array<int, array{value: string, label: string}>
     * }>
     */
    private function definitions(array $selected, array $excluded): array
    {
        $definitions = [];

        foreach ($this->criteria->definitions() as $definition) {
            $key = (string) ($definition['key'] ?? '');

            if (! in_array($key, self::AUTHORABLE_KEYS, true)) {
                continue;
            }

            $savedValues = array_values(array_unique([
                ...($selected[$key] ?? []),
                ...($excluded[$key] ?? []),
            ]));

            if ($key === 'status') {
                $definitions[] = $this->statusDefinition(
                    definition: $definition,
                    selected: $savedValues,
                );

                continue;
            }

            $definitions[] = [
                'key' => $key,
                'label' => $this->criterionLabel(
                    key: $key,
                    fallback: (string) ($definition['label'] ?? $key),
                ),
                'help' => is_string($definition['help'] ?? null)
                    ? $definition['help']
                    : null,
                'options' => $this->optionsWithSelectedValues(
                    options: is_array($definition['options'] ?? null)
                        ? $definition['options']
                        : [],
                    selected: $savedValues,
                ),
            ];
        }

        return $definitions;
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param array<string, array<int, string>> $selected
     * @param array<string, array<int, string>> $excluded
     * @return array<int, array<string, mixed>>
     */
    private function decorateDefinitions(
        array $definitions,
        array $selected,
        array $excluded,
    ): array {
        return array_map(function (array $definition) use ($selected, $excluded): array {
            $key = (string) $definition['key'];
            $options = collect($definition['options'] ?? [])->keyBy('value');
            $selectedValues = $selected[$key] ?? [];
            $excludedValues = $excluded[$key] ?? [];

            return [
                ...$definition,
                'advanced' => in_array($key, self::ADVANCED_KEYS, true),
                'selected_values' => $selectedValues,
                'selected_labels' => array_values(array_map(
                    fn (string $value): string => (string) ($options[$value]['label'] ?? $value),
                    $selectedValues,
                )),
                'excluded_values' => $excludedValues,
                'excluded_labels' => array_values(array_map(
                    fn (string $value): string => (string) ($options[$value]['label'] ?? $value),
                    $excludedValues,
                )),
            ];
        }, $definitions);
    }

    /**
     * Status is the one existing Core filter whose runtime criterion still
     * consumes numeric IDs. Campaign authoring exposes stable ContactStatus
     * keys instead so persisted Campaign eligibility stays portable.
     *
     * @param array{key?: mixed, label?: mixed, help?: mixed, options?: mixed} $definition
     * @param array<int, string> $selected
     * @return array{
     *     key: string,
     *     label: string,
     *     help: string|null,
     *     options: array<int, array{value: string, label: string}>
     * }
     */
    private function statusDefinition(array $definition, array $selected): array
    {
        $selected = $this->stringValues($selected);

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
            'label' => $this->criterionLabel(
                key: 'status',
                fallback: (string) ($definition['label'] ?? 'Status'),
            ),
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

        foreach ($this->stringValues($selected) as $value) {
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
     * @param array<string, mixed> $input
     * @param array<string, array<int, string>> $existing
     * @param array<string, array<string, mixed>> $definitionMap
     * @return array<string, array<int, string>>
     */
    private function normalizeDimension(
        string $field,
        array $input,
        array $existing,
        array $definitionMap,
    ): array {
        $unsupportedKeys = array_values(array_diff(
            array_keys($input),
            array_keys($definitionMap),
        ));

        if ($unsupportedKeys !== []) {
            sort($unsupportedKeys);

            throw ValidationException::withMessages([
                $field => 'Unsupported audience condition(s): '.implode(', ', $unsupportedKeys).'.',
            ]);
        }

        $normalized = [];

        foreach ($definitionMap as $key => $definition) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $values = $this->stringValues($input[$key]);
            $allowedValues = array_column($definition['options'], 'value');
            $invalidValues = array_values(array_diff($values, $allowedValues));

            if ($invalidValues !== []) {
                throw ValidationException::withMessages([
                    "{$field}.{$key}" => 'One or more selected values are not available for this condition.',
                ]);
            }

            if ($values !== []) {
                $normalized[$key] = $values;
            }
        }

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
     * @param array<string, array<int, string>> $exclusions
     * @return array<string, mixed>
     */
    private function composeFilter(array $criteria, array $exclusions): array
    {
        return $exclusions === []
            ? $criteria
            : [
                ...$criteria,
                Campaign::ELIGIBILITY_EXCLUSIONS_KEY => $exclusions,
            ];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array{0: array<string, array<int, string>>, 1: array<string, array<int, string>>}
     */
    private function splitFilter(array $filter): array
    {
        $exclusions = $filter[Campaign::ELIGIBILITY_EXCLUSIONS_KEY] ?? [];
        unset($filter[Campaign::ELIGIBILITY_EXCLUSIONS_KEY]);

        return [
            $this->normalizedStoredCriteria($filter),
            is_array($exclusions)
                ? $this->normalizedStoredCriteria($exclusions)
                : [],
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, array<int, string>>
     */
    private function normalizedStoredCriteria(array $criteria): array
    {
        $normalized = [];

        foreach ($criteria as $key => $values) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $normalizedValues = $this->stringValues($values);

            if ($normalizedValues !== []) {
                $normalized[trim($key)] = $normalizedValues;
            }
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

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param array<string, array<int, string>> $selected
     * @param array<string, array<int, string>> $excluded
     */
    private function audienceSummary(
        array $definitions,
        array $selected,
        array $excluded,
        bool $automatic,
    ): string {
        if (! $automatic) {
            return 'Only leads you add manually';
        }

        $include = $this->conditionSummary($definitions, $selected);

        if ($include === '') {
            return 'Audience rules need attention';
        }

        $summary = 'Leads where '.$include;
        $exclude = $this->conditionSummary($definitions, $excluded);

        if ($exclude !== '') {
            $summary .= ', except leads where '.$exclude;
        }

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $definitions
     * @param array<string, array<int, string>> $selection
     */
    private function conditionSummary(array $definitions, array $selection): string
    {
        $definitionMap = collect($definitions)->keyBy('key');
        $parts = [];

        foreach ($selection as $key => $values) {
            $definition = $definitionMap->get($key);

            if (! is_array($definition)) {
                continue;
            }

            $options = collect($definition['options'] ?? [])->keyBy('value');
            $labels = array_values(array_map(
                fn (string $value): string => (string) ($options[$value]['label'] ?? $value),
                $values,
            ));

            if ($labels === []) {
                continue;
            }

            $parts[] = $this->criterionPhrase($key, $labels);
        }

        return implode(' and ', $parts);
    }

    /** @param array<int, string> $labels */
    private function criterionPhrase(string $key, array $labels): string
    {
        $values = implode(' or ', $labels);

        return match ($key) {
            'status' => 'status is '.$values,
            'relationship' => 'relationship is '.$values,
            'tag' => 'tagged '.$values,
            'webinar_outcome' => 'webinar outcome is '.$values,
            'source' => 'original source is '.$values,
            'subsource' => 'source detail is '.$values,
            default => $this->criterionLabel($key, $key).' is '.$values,
        };
    }

    private function criterionLabel(string $key, string $fallback): string
    {
        return match ($key) {
            'source' => 'Original source',
            'subsource' => 'Source detail',
            'tag' => 'Tags',
            'webinar_outcome' => 'Webinar outcome',
            default => $fallback,
        };
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

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
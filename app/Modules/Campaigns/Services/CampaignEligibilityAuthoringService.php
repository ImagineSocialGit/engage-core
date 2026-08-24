<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
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

    public function __construct(
        private readonly ContactFilterCriterionRegistry $criteria,
        private readonly ContactFilterResolver $resolver,
    ) {}

    /**
     * @return array{
     *     criteria: array<int, array{
     *         key: string,
     *         label: string,
     *         help: string|null,
     *         options: array<int, array{value: string, label: string}>
     *     }>,
     *     selected: array<string, array<int, string>>,
     *     unavailable_criteria: array<int, array{key: string, values: array<int, string>}>,
     *     matching_count: int,
     *     enrollment_modes: array<string, string>,
     *     reentry_policies: array<string, string>,
     *     ineligible_behaviors: array<string, string>
     * }
     */
    public function forCampaign(Campaign $campaign): array
    {
        $selected = $this->storedCriteria($campaign);
        $definitions = $this->definitions($selected);
        $visibleKeys = array_column($definitions, 'key');

        $unavailableCriteria = [];

        foreach ($selected as $key => $values) {
            if (in_array($key, $visibleKeys, true)) {
                continue;
            }

            $unavailableCriteria[] = [
                'key' => $key,
                'values' => $values,
            ];
        }

        return [
            'criteria' => $definitions,
            'selected' => $selected,
            'unavailable_criteria' => $unavailableCriteria,
            'matching_count' => $this->matchingCount($selected),
            'enrollment_modes' => [
                Campaign::ENROLLMENT_MODE_MANUAL => 'Manual only',
                Campaign::ENROLLMENT_MODE_AUTOMATIC => 'Automatic when eligible',
            ],
            'reentry_policies' => [
                Campaign::REENTRY_NEVER => 'Never',
                Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN => 'When they become eligible again',
            ],
            'ineligible_behaviors' => [
                Campaign::INELIGIBLE_CONTINUE => 'Keep the campaign running',
                Campaign::INELIGIBLE_PAUSE => 'Pause the campaign',
                Campaign::INELIGIBLE_CANCEL => 'Stop the campaign',
            ],
        ];
    }

    /**
     * Normalize the operator-editable portion of the eligibility filter while
     * preserving any existing criterion contributed by a module that is not
     * currently available in this authoring surface.
     *
     * @param array<string, mixed> $input
     * @return array<string, array<int, string>>
     */
    public function normalizeForCampaign(Campaign $campaign, array $input): array
    {
        $existing = $this->storedCriteria($campaign);
        $definitions = $this->definitions($existing);

        $definitionMap = [];

        foreach ($definitions as $definition) {
            $definitionMap[$definition['key']] = $definition;
        }

        $unsupportedKeys = array_values(array_diff(
            array_keys($input),
            array_keys($definitionMap),
        ));

        if ($unsupportedKeys !== []) {
            sort($unsupportedKeys);

            throw ValidationException::withMessages([
                'eligibility_criteria' => 'Unsupported eligibility condition(s): '.implode(', ', $unsupportedKeys).'.',
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
                    "eligibility_criteria.{$key}" => 'One or more selected values are not available for this condition.',
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
     */
    public function matchingCount(array $criteria): int
    {
        $runtimeCriteria = $this->runtimeCriteria($criteria);

        if ($runtimeCriteria === null || $runtimeCriteria === []) {
            return 0;
        }

        try {
            return $this->resolver
                ->query([
                    'type' => 'criteria',
                    'criteria' => $runtimeCriteria,
                ])
                ->count();
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    /**
     * @param array<string, array<int, string>> $selected
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     help: string|null,
     *     options: array<int, array{value: string, label: string}>
     * }>
     */
    private function definitions(array $selected): array
    {
        $definitions = [];

        foreach ($this->criteria->definitions() as $definition) {
            $key = (string) ($definition['key'] ?? '');

            if (! in_array($key, self::AUTHORABLE_KEYS, true)) {
                continue;
            }

            if ($key === 'status') {
                $definitions[] = $this->statusDefinition(
                    definition: $definition,
                    selected: $selected['status'] ?? [],
                );

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
                    selected: $selected[$key] ?? [],
                ),
            ];
        }

        return $definitions;
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
     * Preserve stale selected values as visible choices so the operator can
     * explicitly remove them instead of silently losing configuration.
     *
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
     * @return array<string, array<int, string>>
     */
    private function storedCriteria(Campaign $campaign): array
    {
        if (! is_array($campaign->eligibility_filter)) {
            return [];
        }

        $criteria = [];

        foreach ($campaign->eligibility_filter as $key => $values) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $normalizedValues = $this->stringValues($values);

            if ($normalizedValues !== []) {
                $criteria[trim($key)] = $normalizedValues;
            }
        }

        return $criteria;
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
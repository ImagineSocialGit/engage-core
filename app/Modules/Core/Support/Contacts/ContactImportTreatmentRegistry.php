<?php

namespace App\Modules\Core\Support\Contacts;

use App\Modules\Core\Contracts\Contacts\ContactImportTreatmentTarget;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentApplication;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentDefinition;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentResolution;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentSelection;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

final class ContactImportTreatmentRegistry
{
    /**
     * @var array<class-string<ContactImportTreatmentTarget>>
     */
    private array $targets = [];

    /**
     * @var array<string, ContactImportTreatmentTarget>|null
     */
    private ?array $availableTargetsCache = null;

    public function __construct(
        private readonly ContactImportRegistry $imports,
    ) {}

    /**
     * @param class-string<ContactImportTreatmentTarget> $target
     */
    public function registerTarget(string $target): self
    {
        if (! is_subclass_of($target, ContactImportTreatmentTarget::class)) {
            throw new InvalidArgumentException(
                $target.' must implement '.ContactImportTreatmentTarget::class.'.',
            );
        }

        $this->targets[] = $target;
        $this->targets = array_values(array_unique($this->targets));
        $this->availableTargetsCache = null;

        return $this;
    }

    /**
     * @return Collection<int, ContactImportTreatmentDefinition>
     */
    public function definitions(): Collection
    {
        return collect($this->availableTargets())
            ->map(fn (ContactImportTreatmentTarget $target): ContactImportTreatmentDefinition => $target->definition())
            ->sortBy([
                ['section', 'asc'],
                ['sort', 'asc'],
                ['label', 'asc'],
            ])
            ->values();
    }

    /**
     * @param array<string, mixed> $submitted
     * @param array<int, string> $headers
     * @return array<string, ContactImportTreatmentSelection>
     */
    public function normalizeSubmitted(array $submitted, array $headers): array
    {
        $available = $this->availableTargets();
        $normalized = [];

        foreach ($submitted as $targetKey => $selection) {
            if (! is_string($targetKey) || ! isset($available[$targetKey])) {
                throw ValidationException::withMessages([
                    'treatments' => "Unknown or unavailable import treatment [{$targetKey}].",
                ]);
            }

            if (! is_array($selection)) {
                throw ValidationException::withMessages([
                    "treatments.{$targetKey}" => 'Import treatment configuration must be an array.',
                ]);
            }

            $mode = $this->nullableString($selection['mode'] ?? null);

            if ($mode === null || $mode === 'none') {
                continue;
            }

            if (! in_array($mode, [
                ContactImportTreatmentSelection::MODE_FIXED,
                ContactImportTreatmentSelection::MODE_COLUMN,
            ], true)) {
                throw ValidationException::withMessages([
                    "treatments.{$targetKey}.mode" => 'Treatment mode must be fixed or column.',
                ]);
            }

            $target = $available[$targetKey];
            $definition = $target->definition();

            if ($mode === ContactImportTreatmentSelection::MODE_FIXED) {
                $values = $this->submittedValues(
                    values: $selection['fixed_values'] ?? [],
                    custom: $selection['fixed_custom'] ?? null,
                    allowCustom: $definition->allowCustom,
                );
                $values = $target->normalizeValues($values);

                if ($values === []) {
                    throw ValidationException::withMessages([
                        "treatments.{$targetKey}.fixed_values" => 'Choose at least one value for a fixed import treatment.',
                    ]);
                }

                $normalized[$targetKey] = new ContactImportTreatmentSelection(
                    targetKey: $targetKey,
                    mode: $mode,
                    sourceColumn: null,
                    fixedValues: $values,
                    valueMap: [],
                );

                continue;
            }

            $sourceColumn = $this->nullableString($selection['source_column'] ?? null);

            if ($sourceColumn === null || ! in_array($sourceColumn, $headers, true)) {
                throw ValidationException::withMessages([
                    "treatments.{$targetKey}.source_column" => 'Choose a valid CSV source column for this treatment.',
                ]);
            }

            $valueMap = [];
            $submittedMap = $selection['value_map'] ?? [];

            if (! is_array($submittedMap)) {
                throw ValidationException::withMessages([
                    "treatments.{$targetKey}.value_map" => 'Treatment value mapping must be an array.',
                ]);
            }

            foreach ($submittedMap as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $sourceValue = $this->nullableString($entry['source'] ?? null);

                if ($sourceValue === null) {
                    continue;
                }

                $values = $this->submittedValues(
                    values: $entry['values'] ?? [],
                    custom: $entry['custom'] ?? null,
                    allowCustom: $definition->allowCustom,
                );
                $values = $target->normalizeValues($values);

                if ($values !== []) {
                    $valueMap[$sourceValue] = $values;
                }
            }

            if ($valueMap === []) {
                throw ValidationException::withMessages([
                    "treatments.{$targetKey}.value_map" => 'Map at least one source value for a column-based treatment.',
                ]);
            }

            $normalized[$targetKey] = new ContactImportTreatmentSelection(
                targetKey: $targetKey,
                mode: $mode,
                sourceColumn: $sourceColumn,
                fixedValues: [],
                valueMap: $valueMap,
            );
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, ContactImportTreatmentSelection> $selections
     */
    public function resolveRow(array $row, array $selections): ContactImportTreatmentResolution
    {
        $available = $this->availableTargets();
        $fieldOverrides = [];
        $targets = [];

        foreach ($selections as $targetKey => $selection) {
            $target = $available[$targetKey] ?? null;

            if (! $target instanceof ContactImportTreatmentTarget) {
                throw new LogicException(
                    "Import treatment [{$targetKey}] became unavailable during processing.",
                );
            }

            $sourceValue = null;
            $values = $selection->fixedValues;
            $state = 'applied';

            if ($selection->mode === ContactImportTreatmentSelection::MODE_COLUMN) {
                $sourceValue = $this->normalizeSourceValue(
                    $row[$selection->sourceColumn] ?? null,
                );

                if ($sourceValue === null) {
                    $values = [];
                    $state = 'missing';
                } else {
                    $values = $selection->valueMap[$sourceValue] ?? [];
                    $state = $values === [] ? 'unmapped' : 'applied';
                }
            }

            if ($values !== []) {
                foreach ($target->fieldOverrides($values) as $field => $value) {
                    if (! in_array($field, $this->imports->fieldKeys(), true)) {
                        throw new LogicException(
                            "Import treatment [{$targetKey}] attempted to override unknown import field [{$field}].",
                        );
                    }

                    if (isset($fieldOverrides[$field]) && $fieldOverrides[$field] !== $value) {
                        throw ValidationException::withMessages([
                            'treatments' => "Import treatments conflict on field [{$field}].",
                        ]);
                    }

                    $fieldOverrides[$field] = $value;
                }
            }

            $targets[$targetKey] = [
                'state' => $state,
                'source_column' => $selection->sourceColumn,
                'source_value' => $sourceValue,
                'values' => $values,
            ];
        }

        return new ContactImportTreatmentResolution(
            fieldOverrides: $fieldOverrides,
            targets: $targets,
        );
    }

    public function apply(
        ContactImportTreatmentResolution $resolution,
        Contact $contact,
        ContactImportBatch $batch,
        ContactImportOccurrence $occurrence,
        ?Model $actor = null,
    ): void {
        $available = $this->availableTargets();

        foreach ($resolution->targets as $targetKey => $resolved) {
            if ($resolved['state'] !== 'applied' || $resolved['values'] === []) {
                continue;
            }

            $target = $available[$targetKey] ?? null;

            if (! $target instanceof ContactImportTreatmentTarget) {
                throw new LogicException(
                    "Import treatment [{$targetKey}] became unavailable before application.",
                );
            }

            $target->apply(new ContactImportTreatmentApplication(
                contact: $contact,
                batch: $batch,
                occurrence: $occurrence,
                targetKey: $targetKey,
                values: $resolved['values'],
                sourceColumn: $resolved['source_column'],
                sourceValue: $resolved['source_value'],
                actor: $actor,
            ));
        }
    }

    /**
     * @param array<string, ContactImportTreatmentSelection> $selections
     * @return array<string, mixed>
     */
    public function selectionsMeta(array $selections): array
    {
        $meta = [];

        foreach ($selections as $targetKey => $selection) {
            $meta[$targetKey] = $selection->toMeta();
        }

        return $meta;
    }

    /**
     * @param array<string, array{applied_count: int, unmapped_count: int, missing_count: int}> $stats
     * @param array<string, ContactImportTreatmentSelection> $selections
     * @return array<string, mixed>
     */
    public function batchMeta(array $stats, array $selections): array
    {
        $definitions = $this->definitions()->keyBy('key');
        $meta = [];

        foreach ($selections as $targetKey => $selection) {
            $definition = $definitions->get($targetKey);
            $targetStats = $stats[$targetKey] ?? [
                'applied_count' => 0,
                'unmapped_count' => 0,
                'missing_count' => 0,
            ];

            $meta[$targetKey] = [
                'label' => $definition?->label ?? $targetKey,
                ...$selection->toMeta(),
                ...$targetStats,
                'review_required' => $targetStats['unmapped_count'] > 0,
            ];
        }

        return $meta;
    }

    /**
     * @return array<string, ContactImportTreatmentTarget>
     */
    private function availableTargets(): array
    {
        if ($this->availableTargetsCache !== null) {
            return $this->availableTargetsCache;
        }

        $available = [];

        foreach ($this->targets as $targetClass) {
            $target = app($targetClass);

            if (! $target->available()) {
                continue;
            }

            $definition = $target->definition();

            if (isset($available[$definition->key])) {
                throw new LogicException(
                    "Duplicate Contact import treatment key [{$definition->key}].",
                );
            }

            $available[$definition->key] = $target;
        }

        return $this->availableTargetsCache = $available;
    }

    /**
     * @param mixed $values
     * @return array<int, mixed>
     */
    private function submittedValues(
        mixed $values,
        mixed $custom,
        bool $allowCustom,
    ): array {
        $submitted = is_array($values) ? array_values($values) : [];

        if ($allowCustom && is_string($custom) && trim($custom) !== '') {
            foreach (explode(',', $custom) as $value) {
                $submitted[] = $value;
            }
        }

        return $submitted;
    }

    private function normalizeSourceValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
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
<?php

namespace App\Modules\Core\Actions\ContactStatuses;

use App\Modules\Core\Models\ContactStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use App\Support\Presets\Data\ResolvedPresetDomain;
use App\Support\Presets\Enums\PresetDomain;

class SyncContactStatusPresetsAction
{
    /**
     * @return array{
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: array<int, string>,
     * }
     */
/**
     * @return array{
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: array<int, string>,
     * }
     */
    public function handle(ResolvedPresetDomain $resolved, bool $force = false): array
    {
        if ($resolved->domain !== PresetDomain::ContactStatuses) {
            throw new InvalidArgumentException(sprintf(
                'ContactStatus preset sync requires domain [%s]; received [%s].',
                PresetDomain::ContactStatuses->value,
                $resolved->domain->value,
            ));
        }

        $statusDefinitions = [];

        foreach ($resolved->definitions as $statusKey => $definition) {
            $statusDefinitions[] = $this->normalizeDefinition($statusKey, $definition);
        }

        return DB::transaction(function () use ($statusDefinitions, $force) {
            $result = [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [],
            ];

            foreach ($statusDefinitions as $definition) {
                $status = ContactStatus::query()
                    ->where('key', $definition['key'])
                    ->first();

                if (! $status instanceof ContactStatus) {
                    ContactStatus::create([
                        ...$this->attributes($definition),
                        'is_customized' => false,
                        'customized_at' => null,
                    ]);

                    $result['created']++;

                    continue;
                }

                if ($status->is_customized && ! $force) {
                    $result['skipped']++;

                    continue;
                }

                $status->forceFill([
                    ...$this->attributes($definition),
                    'is_customized' => $force ? false : (bool) $status->is_customized,
                    'customized_at' => $force ? null : $status->customized_at,
                ])->save();

                $result['updated']++;
            }

            return $result;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function normalizeDefinition(string $statusKey, array $definition): array
    {
        $key = $this->requiredString($definition['key'] ?? null, "ContactStatus [{$statusKey}] key");
        $name = $this->requiredString($definition['name'] ?? null, "ContactStatus [{$statusKey}] name");

        if ($key !== $statusKey) {
            throw new InvalidArgumentException("ContactStatus definition [{$statusKey}] key must match its definition key.");
        }

        $meta = is_array($definition['meta'] ?? null)
            ? $definition['meta']
            : [];

        $category = $this->nullableString($definition['category'] ?? null)
            ?? $this->nullableString($meta['category'] ?? null);

        unset(
            $meta['description'],
            $meta['category'],
            $meta['color'],
            $meta['source_version'],
        );

        return [
            'key' => $key,
            'name' => $name,
            'description' => $this->nullableString($definition['description'] ?? null),
            'category' => $category,
            'color' => $this->nullableString($definition['color'] ?? null),
            'is_core' => (bool) ($definition['is_core'] ?? true),
            'is_active' => (bool) ($definition['is_active'] ?? true),
            'sort_order' => (int) ($definition['sort_order'] ?? 0),
            'source_version' => $this->nullableVersion($definition['source_version'] ?? null),
            'meta' => $meta,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function attributes(array $definition): array
    {
        return [
            'key' => $definition['key'],
            'name' => $definition['name'],
            'description' => $definition['description'],
            'category' => $definition['category'],
            'color' => $definition['color'],
            'is_core' => $definition['is_core'],
            'is_active' => $definition['is_active'],
            'sort_order' => $definition['sort_order'],
            'source_version' => $definition['source_version'],
            'meta' => $definition['meta'],
        ];
    }

    /**
     * @return array<int, string>
     */

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("Missing required {$field}.");
        }

        return trim($value);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function nullableVersion(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return $this->nullableString($value);
    }
}

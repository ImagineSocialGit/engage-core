<?php

namespace App\Modules\Core\Actions\ContactStatuses;

use App\Modules\Core\Models\ContactStatus;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
    public function handle(?string $presetKey = null, bool $force = false): array
    {
        $presetKey = $this->normalizePresetKey($presetKey);

        if ($presetKey === null) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['No preset key was provided and config[presets.default_package] is empty.'],
            ];
        }

        $package = config("presets.packages.{$presetKey}");

        if (! is_array($package)) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ["Preset package [{$presetKey}] does not exist."],
            ];
        }

        $statusDefinitions = $this->statusDefinitions($presetKey);

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

    private function normalizePresetKey(?string $presetKey): ?string
    {
        $presetKey ??= config('presets.default_package');

        if (! is_string($presetKey)) {
            return null;
        }

        $presetKey = trim($presetKey);

        return $presetKey !== '' ? $presetKey : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function statusDefinitions(string $presetKey): array
    {
        $groups = config("presets.packages.{$presetKey}.groups.contact_statuses", []);

        if (! is_array($groups)) {
            throw new InvalidArgumentException("Preset package [{$presetKey}] groups.contact_statuses must be an array.");
        }

        $groups = $this->normalizeStringList($groups);

        if ($groups === []) {
            return [];
        }

        $statusKeys = [];

        foreach ($groups as $group) {
            $groupStatusKeys = config("presets.contact-statuses.groups.{$group}");

            if (! is_array($groupStatusKeys)) {
                throw new InvalidArgumentException("ContactStatus preset group [{$group}] does not exist.");
            }

            foreach ($this->normalizeStringList($groupStatusKeys) as $statusKey) {
                $statusKeys[] = $statusKey;
            }
        }

        $statusKeys = array_values(array_unique($statusKeys));

        $definitions = [];

        foreach ($statusKeys as $statusKey) {
            $definition = config("presets.contact-statuses.definitions.{$statusKey}");

            if (! is_array($definition)) {
                throw new InvalidArgumentException("ContactStatus preset definition [{$statusKey}] does not exist.");
            }

            $definitions[] = $this->normalizeDefinition($statusKey, $definition);
        }

        return $definitions;
    }

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
    private function normalizeStringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

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

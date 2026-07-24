<?php

namespace App\Support\Presets;

use App\Support\Presets\Enums\PresetDomain;
use InvalidArgumentException;

final class PresetPackageResolver
{
    public function resolvePresetKey(?string $presetKey = null): ?string
    {
        foreach ([
            $presetKey,
            config('client.preset'),
            config('presets.default_package'),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        foreach (array_keys(config('presets.packages', [])) as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function package(string $presetKey): array
    {
        $package = config("presets.packages.{$presetKey}");

        if (! is_array($package)) {
            throw new InvalidArgumentException("Preset package [{$presetKey}] does not exist.");
        }

        return $package;
    }

    /**
     * @return array<int, string>
     */
    public function selectedGroups(string $presetKey, PresetDomain $domain): array
    {
        $package = $this->package($presetKey);
        $groups = data_get($package, "groups.{$domain->value}", []);

        if (! is_array($groups)) {
            throw new InvalidArgumentException(sprintf(
                'Preset package [%s] groups.%s must be an array.',
                $presetKey,
                $domain->value,
            ));
        }

        return $this->normalizeStringList($groups);
    }

    /**
     * @param mixed $values
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
}
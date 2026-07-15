<?php

namespace App\Support\Presets;

use App\Support\Modules\ModuleManager;
use App\Support\Presets\Enums\PresetDomain;
use InvalidArgumentException;

final class PresetPackageResolver
{
    public function __construct(
        private readonly ModuleManager $moduleManager,
    ) {}

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
     * Effective package modules plus client add/remove overrides.
     *
     * This describes preset-package composition. It is not the runtime module
     * source of truth; runtime availability belongs to ModuleManager.
     *
     * @return array<int, string>
     */
    public function effectiveModules(string $presetKey): array
    {
        $package = $this->package($presetKey);

        $packageModules = $this->normalizeStringList(
            data_get($package, 'modules.enabled', [])
        );

        $addedModules = $this->normalizeStringList(
            config('client.modules.add', [])
        );

        $removedModules = $this->normalizeStringList(
            config('client.modules.remove', [])
        );

        $modules = array_values(array_unique([
            ...array_keys(array_filter(
                $this->moduleManager->definitions(),
                fn (mixed $definition): bool => is_array($definition)
                    && (bool) ($definition['always_on'] ?? false),
            )),
            ...$packageModules,
            ...$addedModules,
        ]));

        return array_values(array_diff($modules, $removedModules));
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

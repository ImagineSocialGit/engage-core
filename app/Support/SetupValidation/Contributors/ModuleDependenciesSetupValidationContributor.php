<?php

namespace App\Support\SetupValidation\Contributors;

use App\Support\Modules\ModuleManager;
use App\Support\Presets\PresetPackageResolver;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Throwable;

class ModuleDependenciesSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'modules';
    private const MODULE = 'app';

    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly PresetPackageResolver $packageResolver,
    ) {}

    public function findings(): iterable
    {
        $definitions = $this->moduleManager->definitions();
        $enabled = config('modules.enabled', []);

        if (! is_array($enabled)) {
            yield $this->error(
                code: 'app.modules.enabled_invalid',
                message: 'modules.enabled must be an array.',
                path: 'modules.enabled',
            );

            return;
        }

        foreach ($enabled as $index => $moduleKey) {
            if (! is_string($moduleKey) || trim($moduleKey) === '') {
                yield $this->error(
                    code: 'app.modules.enabled_key_invalid',
                    message: 'Explicitly enabled module keys must be non-empty strings.',
                    path: "modules.enabled.{$index}",
                );

                continue;
            }

            $moduleKey = trim($moduleKey);

            if (! array_key_exists($moduleKey, $definitions)) {
                yield $this->error(
                    code: 'app.modules.enabled_unknown',
                    message: "Explicitly enabled module [{$moduleKey}] is not defined.",
                    path: "modules.enabled.{$index}",
                    context: ['module_key' => $moduleKey],
                );
            }
        }

        foreach ($definitions as $moduleKey => $definition) {
            if (! is_string($moduleKey) || trim($moduleKey) === '' || ! is_array($definition)) {
                yield $this->error(
                    code: 'app.modules.definition_invalid',
                    message: 'Every module definition must use a non-empty string key and array definition.',
                    path: 'modules.modules',
                );

                continue;
            }

            $dependencies = $definition['depends_on'] ?? [];

            if (! is_array($dependencies)) {
                yield $this->error(
                    code: 'app.modules.dependencies_invalid',
                    message: "Module [{$moduleKey}] depends_on must be an array.",
                    path: "modules.modules.{$moduleKey}.depends_on",
                    context: ['module_key' => $moduleKey],
                );

                continue;
            }

            foreach ($dependencies as $dependencyIndex => $dependency) {
                if (! is_string($dependency) || trim($dependency) === '') {
                    yield $this->error(
                        code: 'app.modules.dependency_key_invalid',
                        message: "Module [{$moduleKey}] contains an invalid dependency key.",
                        path: "modules.modules.{$moduleKey}.depends_on.{$dependencyIndex}",
                        context: ['module_key' => $moduleKey],
                    );

                    continue;
                }

                $dependency = trim($dependency);

                if (! array_key_exists($dependency, $definitions)) {
                    yield $this->error(
                        code: 'app.modules.dependency_unknown',
                        message: "Module [{$moduleKey}] depends on unknown module [{$dependency}].",
                        path: "modules.modules.{$moduleKey}.depends_on.{$dependencyIndex}",
                        context: [
                            'module_key' => $moduleKey,
                            'dependency_key' => $dependency,
                        ],
                    );
                }
            }
        }

        foreach ($this->dependencyCycles($definitions) as $cycle) {
            yield $this->error(
                code: 'app.modules.dependency_cycle',
                message: 'Module dependency cycle detected: '.implode(' -> ', $cycle).'.',
                path: 'modules.modules',
                context: ['cycle' => $cycle],
            );
        }

        $availableKeys = $this->moduleManager->enabledKeysWithDependencies();

        foreach ($availableKeys as $moduleKey) {
            if (! $this->moduleManager->known($moduleKey)) {
                continue;
            }

            $definition = $definitions[$moduleKey] ?? [];
            $requiresProvider = $definition['requires_provider'] ?? true;

            if (! is_bool($requiresProvider)) {
                yield $this->error(
                    code: 'app.modules.requires_provider_invalid',
                    message: "Module [{$moduleKey}] requires_provider must be boolean when configured.",
                    path: "modules.modules.{$moduleKey}.requires_provider",
                    context: ['module_key' => $moduleKey],
                );

                continue;
            }

            if (! $requiresProvider) {
                continue;
            }

            $providers = $this->moduleManager->providers($moduleKey);

            if ($providers === []) {
                yield $this->error(
                    code: 'app.modules.provider_missing',
                    message: "Available module [{$moduleKey}] has no configured provider.",
                    path: "modules.modules.{$moduleKey}.providers",
                    context: ['module_key' => $moduleKey],
                );

                continue;
            }

            foreach ($providers as $provider) {
                if (class_exists($provider)) {
                    continue;
                }

                yield $this->error(
                    code: 'app.modules.provider_class_missing',
                    message: "Available module [{$moduleKey}] references missing provider class [{$provider}].",
                    path: "modules.modules.{$moduleKey}.providers",
                    context: [
                        'module_key' => $moduleKey,
                        'provider' => $provider,
                    ],
                );
            }
        }

        yield from $this->validateSelectedPresetModuleRequirements();
    }

    /**
     * @return iterable<int, SetupValidationFinding>
     */
    private function validateSelectedPresetModuleRequirements(): iterable
    {
        $presetKey = $this->packageResolver->resolvePresetKey();

        if ($presetKey === null) {
            return;
        }

        try {
            $package = $this->packageResolver->package($presetKey);
        } catch (Throwable) {
            return;
        }

        $required = data_get($package, 'modules.enabled', []);

        if (! is_array($required)) {
            yield $this->error(
                code: 'app.modules.preset_requirements_invalid',
                message: "Selected preset package [{$presetKey}] modules.enabled must be an array.",
                path: "presets.packages.{$presetKey}.modules.enabled",
                context: ['preset_key' => $presetKey],
            );

            return;
        }

        $available = $this->moduleManager->enabledKeysWithDependencies();

        foreach ($required as $index => $moduleKey) {
            if (! is_string($moduleKey) || trim($moduleKey) === '') {
                yield $this->error(
                    code: 'app.modules.preset_required_key_invalid',
                    message: "Selected preset package [{$presetKey}] contains an invalid required module key.",
                    path: "presets.packages.{$presetKey}.modules.enabled.{$index}",
                    context: ['preset_key' => $presetKey],
                );

                continue;
            }

            $moduleKey = trim($moduleKey);

            if (! $this->moduleManager->known($moduleKey)) {
                yield $this->error(
                    code: 'app.modules.preset_required_module_unknown',
                    message: "Selected preset package [{$presetKey}] requires unknown module [{$moduleKey}].",
                    path: "presets.packages.{$presetKey}.modules.enabled.{$index}",
                    context: [
                        'preset_key' => $presetKey,
                        'module_key' => $moduleKey,
                    ],
                );

                continue;
            }

            if (! in_array($moduleKey, $available, true)) {
                yield $this->error(
                    code: 'app.modules.preset_required_module_unavailable',
                    message: "Selected preset package [{$presetKey}] requires unavailable module [{$moduleKey}].",
                    path: "presets.packages.{$presetKey}.modules.enabled.{$index}",
                    context: [
                        'preset_key' => $presetKey,
                        'module_key' => $moduleKey,
                    ],
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @return array<int, array<int, string>>
     */
    private function dependencyCycles(array $definitions): array
    {
        $cycles = [];
        $visited = [];

        foreach (array_keys($definitions) as $moduleKey) {
            $this->visit(
                moduleKey: $moduleKey,
                definitions: $definitions,
                visiting: [],
                visited: $visited,
                cycles: $cycles,
            );
        }

        $unique = [];

        foreach ($cycles as $cycle) {
            $signature = implode('>', $cycle);
            $unique[$signature] = $cycle;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<int, string> $visiting
     * @param array<string, bool> $visited
     * @param array<int, array<int, string>> $cycles
     */
    private function visit(
        string $moduleKey,
        array $definitions,
        array $visiting,
        array &$visited,
        array &$cycles,
    ): void {
        if (isset($visited[$moduleKey])) {
            return;
        }

        $cycleIndex = array_search($moduleKey, $visiting, true);

        if ($cycleIndex !== false) {
            $cycle = array_slice($visiting, $cycleIndex);
            $cycle[] = $moduleKey;
            $cycles[] = $cycle;

            return;
        }

        $visiting[] = $moduleKey;
        $dependencies = $definitions[$moduleKey]['depends_on'] ?? [];

        if (is_array($dependencies)) {
            foreach ($dependencies as $dependency) {
                if (! is_string($dependency) || ! array_key_exists($dependency, $definitions)) {
                    continue;
                }

                $this->visit($dependency, $definitions, $visiting, $visited, $cycles);
            }
        }

        $visited[$moduleKey] = true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function error(
        string $code,
        string $message,
        string $path,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $path,
            module: self::MODULE,
            context: $context,
        );
    }
}

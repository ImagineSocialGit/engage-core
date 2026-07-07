<?php

namespace App\Support\Modules;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;

class ModuleManager
{
    /**
     * Explicitly enabled module keys plus core.
     *
     * @return array<string>
     */
    public function enabledKeys(): array
    {
        $enabled = config('modules.enabled', []);
        $definitions = $this->definitions();

        $alwaysOnKeys = array_keys(array_filter(
            $definitions,
            fn (mixed $definition): bool => is_array($definition)
                && (bool) ($definition['always_on'] ?? false),
        ));

        if (! is_array($enabled)) {
            return array_values(array_unique($alwaysOnKeys ?: ['core']));
        }

        $keys = array_values(array_filter(
            array_map('strval', $enabled),
            fn (string $key): bool => $key !== ''
        ));

        return array_values(array_unique([
            ...$alwaysOnKeys,
            ...$keys,
        ]));
    }

    /**
     * Enabled module keys plus required dependency keys.
     *
     * @return array<string>
     */
    public function enabledKeysWithDependencies(): array
    {
        $resolved = [];

        foreach ($this->enabledKeys() as $key) {
            $this->addEnabledKeyWithDependencies($key, $resolved);
        }

        return array_values(array_unique($resolved));
    }

    public function enabled(string $key): bool
    {
        return in_array($key, $this->enabledKeys(), true);
    }

    public function disabled(string $key): bool
    {
        return ! $this->enabled($key);
    }

    public function require(string $key): void
    {
        abort_if($this->disabled($key), 404);
    }

    /**
     * @return array<string>
     */
    public function dependencies(string $key): array
    {
        return array_values(array_filter(
            Arr::wrap(config("modules.modules.{$key}.depends_on", [])),
            fn (mixed $dependency): bool => is_string($dependency) && $dependency !== '',
        ));
    }

    public function known(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        $definitions = config('modules.modules', []);

        return is_array($definitions) ? $definitions : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function enabledDefinitions(): array
    {
        return array_intersect_key(
            $this->definitions(),
            array_flip($this->enabledKeys()),
        );
    }

    /**
     * @return array<int, array{module: string, label: string, route: string, href: string, priority: int, class: string}>
     */
    public function navigationItems(): array
    {
        $items = [];

        foreach ($this->enabledDefinitions() as $moduleKey => $definition) {
            $navItems = $this->normalizeNavigationItems($definition['nav'] ?? []);

            foreach ($navItems as $item) {
                $route = $item['route'] ?? null;

                if (! is_string($route) || $route === '' || ! Route::has($route)) {
                    continue;
                }

                $label = $this->navigationLabel($item, $definition, $moduleKey);

                $items[] = [
                    'module' => $moduleKey,
                    'label' => $label,
                    'route' => $route,
                    'href' => route($route),
                    'priority' => (int) ($item['priority'] ?? 100),
                    'class' => is_string($item['class'] ?? null) ? (string) $item['class'] : '',
                ];
            }
        }

        usort($items, function (array $a, array $b): int {
            $priority = $a['priority'] <=> $b['priority'];

            if ($priority !== 0) {
                return $priority;
            }

            return strnatcasecmp($a['label'], $b['label']);
        });

        return array_values($items);
    }


    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $definition
     */
    private function navigationLabel(array $item, array $definition, string $moduleKey): string
    {
        $labelConfig = $item['label_config'] ?? null;

        if (is_string($labelConfig) && $labelConfig !== '') {
            $configuredLabel = config($labelConfig);

            if (is_string($configuredLabel) && trim($configuredLabel) !== '') {
                return trim($configuredLabel);
            }
        }

        $label = $item['label'] ?? $definition['name'] ?? $moduleKey;

        if (! is_string($label) || trim($label) === '') {
            return $moduleKey;
        }

        return trim($label);
    }

    /**
     * @return array<class-string>
     */
    public function providers(string $key): array
    {
        return array_values(array_filter(
            Arr::wrap(config("modules.modules.{$key}.providers", [])),
            fn (mixed $provider): bool => is_string($provider) && $provider !== '',
        ));
    }

    /**
     * @return array<class-string>
     */
    public function enabledProviders(): array
    {
        $providers = [];

        foreach ($this->enabledKeysWithDependencies() as $key) {
            foreach ($this->providers($key) as $provider) {
                $providers[] = $provider;
            }
        }

        return array_values(array_unique($providers));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeNavigationItems(mixed $nav): array
    {
        if (! is_array($nav) || $nav === []) {
            return [];
        }

        if (array_is_list($nav)) {
            return array_values(array_filter(
                $nav,
                fn (mixed $item): bool => is_array($item),
            ));
        }

        return [$nav];
    }

    /**
     * @param  array<int, string>  $resolved
     * @param  array<int, string>  $resolving
     */
    private function addEnabledKeyWithDependencies(
        string $key,
        array &$resolved,
        array $resolving = [],
    ): void {
        if (in_array($key, $resolved, true)) {
            return;
        }

        if (in_array($key, $resolving, true)) {
            return;
        }

        if (! $this->known($key)) {
            $resolved[] = $key;

            return;
        }

        $resolving[] = $key;

        foreach ($this->dependencies($key) as $dependency) {
            $this->addEnabledKeyWithDependencies($dependency, $resolved, $resolving);
        }

        $resolved[] = $key;
    }
}

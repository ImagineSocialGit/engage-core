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
     * Preset contributors are discovered from every installed module definition,
     * independently from runtime module enablement.
     *
     * @return array<class-string>
     */
    public function presetContributorClasses(): array
    {
        $contributors = [];

        foreach ($this->definitions() as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            foreach (Arr::wrap($definition['preset_contributors'] ?? []) as $contributor) {
                if (! is_string($contributor) || trim($contributor) === '') {
                    continue;
                }

                $contributors[] = trim($contributor);
            }
        }

        return array_values(array_unique($contributors));
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
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     description: string,
     *     priority: int
     * }>
     */
    public function settingsCategories(): array
    {
        $categories = [];

        foreach (Arr::wrap(config('modules.settings.categories', [])) as $key => $category) {
            if (! is_string($key) || trim($key) === '' || ! is_array($category)) {
                continue;
            }

            $label = $category['label'] ?? null;
            $description = $category['description'] ?? null;

            if (! is_string($label) || trim($label) === ''
                || ! is_string($description) || trim($description) === '') {
                continue;
            }

            $categories[] = [
                'key' => trim($key),
                'label' => trim($label),
                'description' => trim($description),
                'priority' => (int) ($category['priority'] ?? 100),
            ];
        }

        usort($categories, function (array $a, array $b): int {
            $priority = $a['priority'] <=> $b['priority'];

            return $priority !== 0
                ? $priority
                : strnatcasecmp($a['label'], $b['label']);
        });

        return array_values($categories);
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     module: string,
     *     category: string,
     *     label: string,
     *     description: string,
     *     route: string,
     *     href: string,
     *     priority: int
     * }>
     */
    public function settingsItems(): array
    {
        $items = [];

        foreach ($this->enabledDefinitions() as $moduleKey => $definition) {
            foreach ($this->normalizeSettingsItems($definition['settings'] ?? []) as $item) {
                $route = $item['route'] ?? null;

                if (! is_string($route) || trim($route) === '' || ! Route::has($route)) {
                    continue;
                }

                $key = $item['key'] ?? null;
                $category = $item['category'] ?? null;
                $label = $item['label'] ?? null;
                $description = $item['description'] ?? null;

                if (! is_string($key) || trim($key) === ''
                    || ! is_string($category) || trim($category) === ''
                    || ! is_string($label) || trim($label) === ''
                    || ! is_string($description) || trim($description) === '') {
                    continue;
                }

                $items[] = [
                    'key' => $moduleKey.'.'.trim($key),
                    'module' => $moduleKey,
                    'category' => trim($category),
                    'label' => trim($label),
                    'description' => trim($description),
                    'route' => trim($route),
                    'href' => route(trim($route)),
                    'priority' => (int) ($item['priority'] ?? 100),
                ];
            }
        }

        usort($items, function (array $a, array $b): int {
            $priority = $a['priority'] <=> $b['priority'];

            return $priority !== 0
                ? $priority
                : strnatcasecmp($a['label'], $b['label']);
        });

        return array_values($items);
    }

    /**
     * @return array<int, array{
     *     key: string,
     *     module: string,
     *     label: string,
     *     description: string,
     *     route: string,
     *     href: string,
     *     priority: int
     * }>
     */
    public function gettingStartedItems(): array
    {
        $items = [];

        foreach (Arr::wrap(config('modules.settings.getting_started.items', [])) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $moduleKey = $item['module'] ?? null;
            $route = $item['route'] ?? null;
            $key = $item['key'] ?? null;
            $label = $item['label'] ?? null;
            $description = $item['description'] ?? null;

            if (! is_string($moduleKey) || ! $this->enabled($moduleKey)
                || ! is_string($route) || ! Route::has($route)
                || ! is_string($key) || trim($key) === ''
                || ! is_string($label) || trim($label) === ''
                || ! is_string($description) || trim($description) === '') {
                continue;
            }

            $items[] = [
                'key' => trim($key),
                'module' => $moduleKey,
                'label' => trim($label),
                'description' => trim($description),
                'route' => $route,
                'href' => route($route),
                'priority' => (int) ($item['priority'] ?? 100),
            ];
        }

        usort($items, function (array $a, array $b): int {
            $priority = $a['priority'] <=> $b['priority'];

            return $priority !== 0
                ? $priority
                : strnatcasecmp($a['label'], $b['label']);
        });

        $maximum = max(0, (int) config('modules.settings.getting_started.max', 3));

        return array_slice(array_values($items), 0, $maximum);
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
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSettingsItems(mixed $settings): array
    {
        if (! is_array($settings) || $settings === []) {
            return [];
        }

        if (array_is_list($settings)) {
            return array_values(array_filter(
                $settings,
                fn (mixed $item): bool => is_array($item),
            ));
        }

        return [$settings];
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
<?php

namespace App\Support\Dashboard;

use App\Support\Dashboard\Contracts\DashboardPanelProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardPanelRegistry
{
    private const TAG = 'crm.dashboard_panel_providers';

    /**
     * @return Collection<string, Collection<int, array<string, mixed>>>
     */
    public function panelsFor(Request $request): Collection
    {
        $registered = collect(app()->tagged(self::TAG))
            ->filter(fn (mixed $provider): bool => $provider instanceof DashboardPanelProvider)
            ->mapWithKeys(fn (DashboardPanelProvider $provider): array => [$provider->key() => $provider]);

        return collect($this->dashboardSlots())
            ->mapWithKeys(function (array $slotConfig, string $slot) use ($registered, $request): array {
                $configuredKeys = collect((array) ($slotConfig['panels'] ?? []))
                    ->map(fn (mixed $key): string => (string) $key)
                    ->filter(fn (string $key): bool => $key !== '')
                    ->values();

                $max = (int) ($slotConfig['max'] ?? 99);

                if ($max <= 0 || $configuredKeys->isEmpty()) {
                    return [$slot => collect()];
                }

                $panels = $configuredKeys
                    ->map(fn (string $key): mixed => $registered->get($key))
                    ->filter()
                    ->map(fn (DashboardPanelProvider $provider): ?array => $this->resolvePanel(
                        provider: $provider,
                        request: $request,
                        slot: $slot,
                        slotConfig: $slotConfig,
                        order: $configuredKeys->search($provider->key(), true),
                    ))
                    ->filter()
                    ->sort(function (array $a, array $b): int {
                        $priority = ((int) ($b['priority'] ?? 0)) <=> ((int) ($a['priority'] ?? 0));

                        if ($priority !== 0) {
                            return $priority;
                        }

                        return ((int) ($a['order'] ?? 999)) <=> ((int) ($b['order'] ?? 999));
                    })
                    ->take($max)
                    ->values();

                return [$slot => $panels];
            });
    }

    public static function providerTag(): string
    {
        return self::TAG;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function dashboardSlots(): array
    {
        $dashboard = config('modules.dashboard', []);

        if (! is_array($dashboard)) {
            return [];
        }

        $slots = $dashboard['slots'] ?? [];

        if (! is_array($slots)) {
            $slots = [];
        }

        $preset = config('client.preset');
        $presetConfig = is_string($preset) && $preset !== ''
            ? ($dashboard['presets'][$preset] ?? null)
            : null;

        if (is_array($presetConfig)) {
            $slots = $this->mergeDashboardSlots($slots, $presetConfig['slots'] ?? []);
        }

        return is_array($slots) ? $slots : [];
    }

    /**
     * @param array<string, mixed> $baseSlots
     * @param mixed $presetSlots
     * @return array<string, array<string, mixed>>
     */
    private function mergeDashboardSlots(array $baseSlots, mixed $presetSlots): array
    {
        if (! is_array($presetSlots)) {
            return $baseSlots;
        }

        foreach ($presetSlots as $slot => $slotOverrides) {
            if (! is_string($slot) || ! is_array($slotOverrides)) {
                continue;
            }

            $base = is_array($baseSlots[$slot] ?? null) ? $baseSlots[$slot] : [];

            foreach ($slotOverrides as $key => $value) {
                // Ordered panel lists are intentional, so a preset/client list
                // replaces the default list instead of merging by numeric index.
                $base[$key] = $value;
            }

            $baseSlots[$slot] = $base;
        }

        return $baseSlots;
    }

    /**
     * @param array<string, mixed> $slotConfig
     * @return array<string, mixed>|null
     */
    private function resolvePanel(
        DashboardPanelProvider $provider,
        Request $request,
        string $slot,
        array $slotConfig,
        int|bool $order,
    ): ?array {
        if (! module_enabled($provider->module())) {
            return null;
        }

        $panel = $provider->panel($request);

        if (! is_array($panel)) {
            return null;
        }

        $panel['key'] ??= $provider->key();
        $panel['module'] ??= $provider->module();
        $panel['slot'] ??= $slot;
        $panel['target_ref'] ??= str_replace(['.', '-'], '_', $provider->key()).'Panel';
        $panel['order'] = is_int($order) ? $order : ((int) ($panel['order'] ?? 999));

        $priorityOverrides = $slotConfig['priorities'] ?? [];

        if (is_array($priorityOverrides) && array_key_exists($provider->key(), $priorityOverrides)) {
            $panel['priority'] = (int) $priorityOverrides[$provider->key()];
        }

        $count = (int) ($panel['count'] ?? 0);
        $hideWhenEmpty = (bool) ($panel['hide_when_empty'] ?? $slotConfig['hide_when_empty'] ?? false);
        $items = $panel['items'] ?? [];
        $hasItems = $items instanceof Collection
            ? $items->isNotEmpty()
            : (is_countable($items) && count($items) > 0);

        if ($hideWhenEmpty && $count <= 0 && ! $hasItems) {
            return null;
        }

        return $panel;
    }
}

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

        return collect((array) config('modules.dashboard.slots', []))
            ->mapWithKeys(function (array $slotConfig, string $slot) use ($registered, $request): array {
                $configuredKeys = collect((array) ($slotConfig['panels'] ?? []))
                    ->map(fn (mixed $key): string => (string) $key)
                    ->filter(fn (string $key): bool => $key !== '');

                $max = max(1, (int) ($slotConfig['max'] ?? 99));

                $panels = $configuredKeys
                    ->map(fn (string $key): mixed => $registered->get($key))
                    ->filter()
                    ->map(fn (DashboardPanelProvider $provider): ?array => $this->resolvePanel($provider, $request, $slot, $slotConfig))
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
     * @param array<string, mixed> $slotConfig
     * @return array<string, mixed>|null
     */
    private function resolvePanel(
        DashboardPanelProvider $provider,
        Request $request,
        string $slot,
        array $slotConfig,
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

        $count = (int) ($panel['count'] ?? 0);
        $hideWhenEmpty = (bool) ($panel['hide_when_empty'] ?? $slotConfig['hide_when_empty'] ?? false);

        if ($hideWhenEmpty && $count <= 0 && empty($panel['items'])) {
            return null;
        }

        return $panel;
    }
}

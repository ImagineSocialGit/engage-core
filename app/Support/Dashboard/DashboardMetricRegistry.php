<?php

namespace App\Support\Dashboard;

use App\Support\Dashboard\Contracts\DashboardMetricProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardMetricRegistry
{
    private const TAG = 'crm.dashboard_metric_providers';

    public static function providerTag(): string
    {
        return self::TAG;
    }

    /**
     * Metric keys must be explicitly selected through preset/client configuration.
     * Unselected and disabled-module providers do not execute metric queries.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function metricsFor(Request $request): Collection
    {
        $config = $this->metricConfig();
        $configuredKeys = $config['keys'] ?? [];

        if (! is_array($configuredKeys)) {
            return collect();
        }

        $keys = collect($configuredKeys)
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->unique()
            ->values();
        $max = max(0, min(4, (int) ($config['max'] ?? 4)));

        if ($max === 0 || $keys->isEmpty()) {
            return collect();
        }

        $registered = collect(app()->tagged(self::TAG))
            ->filter(fn (mixed $provider): bool => $provider instanceof DashboardMetricProvider)
            ->mapWithKeys(fn (DashboardMetricProvider $provider): array => [$provider->key() => $provider]);
        $priorities = is_array($config['priorities'] ?? null) ? $config['priorities'] : [];

        return $keys
            ->map(function (string $key, int $order) use ($registered, $request, $priorities): ?array {
                $provider = $registered->get($key);

                if (! $provider instanceof DashboardMetricProvider || ! module_enabled($provider->module())) {
                    return null;
                }

                $metric = $provider->metric($request);

                if (! is_array($metric)) {
                    return null;
                }

                $normalized = $this->normalize($metric, $request);

                if ($normalized === null) {
                    return null;
                }

                $override = $priorities[$key] ?? null;

                return [
                    ...$normalized,
                    'key' => $provider->key(),
                    'module' => $provider->module(),
                    'priority' => is_int($override)
                        ? $override
                        : (is_int($metric['priority'] ?? null) ? $metric['priority'] : 0),
                    'order' => $order,
                ];
            })
            ->filter()
            ->sort(function (array $a, array $b): int {
                $priority = $b['priority'] <=> $a['priority'];

                return $priority !== 0 ? $priority : $a['order'] <=> $b['order'];
            })
            ->take($max)
            ->values();
    }

    /** @return array<string, mixed> */
    private function metricConfig(): array
    {
        $dashboard = config('modules.dashboard', []);
        $dashboard = is_array($dashboard) ? $dashboard : [];
        $base = is_array($dashboard['metrics'] ?? null) ? $dashboard['metrics'] : [];
        $preset = config('client.preset');

        if (is_string($preset) && $preset !== '') {
            $presetConfig = $dashboard['presets'][$preset]['metrics'] ?? null;

            if (is_array($presetConfig)) {
                $base = array_replace($base, $presetConfig);
            }
        }

        $clientConfig = config('client.dashboard.metrics', []);

        return is_array($clientConfig) ? array_replace($base, $clientConfig) : $base;
    }

    /**
     * @param array<string, mixed> $metric
     * @return array<string, mixed>|null
     */
    private function normalize(array $metric, Request $request): ?array
    {
        $label = $metric['label'] ?? null;
        $format = $metric['format'] ?? null;
        $value = $metric['value'] ?? null;

        if (! is_string($label) || trim($label) === ''
            || ! in_array($format, ['count', 'currency'], true)
            || ! is_int($value)) {
            return null;
        }

        $displayValue = $format === 'count'
            ? number_format($value)
            : $this->formatCurrency($value, $metric);

        if ($displayValue === null) {
            return null;
        }

        $period = $metric['period'] ?? null;

        return [
            'label' => trim($label),
            'display_value' => $displayValue,
            'period' => is_string($period) && trim($period) !== '' ? trim($period) : null,
            'href' => $this->safeHref($metric['href'] ?? null, $request),
        ];
    }

    /**
     * @param array<string, mixed> $metric
     */
    private function formatCurrency(int $amountMinor, array $metric): ?string
    {
        $currency = $metric['currency'] ?? 'USD';
        $digits = $metric['minor_unit_digits'] ?? 2;

        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1
            || ! is_int($digits) || $digits < 0 || $digits > 3
            || $amountMinor === PHP_INT_MIN) {
            return null;
        }

        // All arithmetic stays in integer minor units, avoiding float rounding
        // for financial dashboard totals. The provider declares the currency scale.
        $scale = 10 ** $digits;
        $absolute = abs($amountMinor);
        $whole = number_format(intdiv($absolute, $scale));
        $fraction = $digits > 0
            ? '.'.str_pad((string) ($absolute % $scale), $digits, '0', STR_PAD_LEFT)
            : '';
        $prefix = $currency === 'USD' ? '$' : $currency.' ';

        return ($amountMinor < 0 ? '-' : '').$prefix.$whole.$fraction;
    }

    private function safeHref(mixed $href, Request $request): ?string
    {
        if (! is_string($href) || $href === ''
            || str_contains($href, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $href) === 1) {
            return null;
        }

        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return $href;
        }

        $parts = parse_url($href);

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || ! isset($parts['host'])
            || strcasecmp((string) $parts['host'], $request->getHost()) !== 0
            || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $href;
    }
}
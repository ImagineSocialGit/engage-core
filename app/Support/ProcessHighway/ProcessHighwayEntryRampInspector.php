<?php

namespace App\Support\ProcessHighway;

use App\Support\ProcessHighway\Contracts\ProcessHighwayEntryRampContributor;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;

final class ProcessHighwayEntryRampInspector
{
    public const CONTRIBUTOR_TAG = 'process_highway.entry_ramp_contributors';

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param array<string, mixed> $graph
     * @return array<string, mixed>
     */
    public function decorate(array $graph): array
    {
        $providers = $this->providers();
        $segmentsByKey = collect($graph['segments'] ?? [])->keyBy('key');
        $edgesByTarget = collect($graph['edges'] ?? [])->groupBy('to_node_key');
        $entryRampKeys = collect($graph['highways'] ?? [])
            ->flatMap(fn (array $highway): array => $highway['entry_node_keys'] ?? [])
            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values();
        $nodesByKey = collect($graph['nodes'] ?? [])->keyBy('key');
        $inspectors = [];

        foreach ($entryRampKeys as $entryRampKey) {
            $node = $nodesByKey->get($entryRampKey);

            if (! is_array($node)) {
                continue;
            }

            $criterionKey = $node['attributes']['criterion_key'] ?? null;
            $value = $node['attributes']['value'] ?? null;

            if (! is_string($criterionKey) || $criterionKey === ''
                || ! is_string($value) || $value === ''
            ) {
                continue;
            }

            $provider = $providers[$criterionKey] ?? null;

            if (! $provider instanceof ProcessHighwayEntryRampContributor) {
                continue;
            }

            $inspection = $provider->inspect($value, $node);
            $applicationSources = [
                ...$this->normalizeSources($inspection['application_sources'] ?? []),
                ...$this->configuredFlowRouteSources(
                    nodeKey: $entryRampKey,
                    edges: $edgesByTarget->get($entryRampKey, collect())->all(),
                    segmentsByKey: $segmentsByKey,
                ),
            ];

            $inspectors[$entryRampKey] = [
                'key' => $entryRampKey,
                'label' => $node['label'],
                'criterion_key' => $criterionKey,
                'criterion_label' => $this->criterionLabel($criterionKey),
                'value' => $value,
                'value_label' => $node['attributes']['value_label']
                    ?? $this->valueLabel($node, $criterionKey, $value),
                'contact_count' => max(0, (int) ($inspection['contact_count'] ?? 0)),
                'application_sources' => collect($applicationSources)
                    ->unique('key')
                    ->values()
                    ->all(),
            ];
        }

        $highways = is_array($graph['highways'] ?? null)
            ? $graph['highways']
            : [];

        foreach ($highways as &$highway) {
            $entryNodes = is_array($highway['entry_nodes'] ?? null)
                ? $highway['entry_nodes']
                : [];

            foreach ($entryNodes as &$entryNode) {
                $entryNode['inspector'] = $inspectors[$entryNode['key']] ?? null;
            }
            unset($entryNode);

            $highway['entry_nodes'] = $entryNodes;
        }
        unset($highway);

        $graph['highways'] = $highways;
        $graph['entry_ramp_inspectors'] = $inspectors;

        return $graph;
    }

    /** @return array<string, ProcessHighwayEntryRampContributor> */
    private function providers(): array
    {
        $providers = [];

        foreach ($this->container->tagged(self::CONTRIBUTOR_TAG) as $provider) {
            if (! $provider instanceof ProcessHighwayEntryRampContributor) {
                continue;
            }

            $criterionKey = trim($provider->criterionKey());

            if ($criterionKey !== '') {
                $providers[$criterionKey] ??= $provider;
            }
        }

        return $providers;
    }

    /**
     * @param array<int, mixed> $sources
     * @return array<int, array{key: string, label: string, detail: string, url?: string}>
     */
    private function normalizeSources(array $sources): array
    {
        return array_values(array_filter(array_map(
            function (mixed $source): ?array {
                if (! is_array($source)) {
                    return null;
                }

                $key = $this->string($source['key'] ?? null);
                $label = $this->string($source['label'] ?? null);
                $detail = $this->string($source['detail'] ?? null);
                $url = $this->string($source['url'] ?? null);

                if ($key === null || $label === null || $detail === null) {
                    return null;
                }

                return array_filter([
                    'key' => $key,
                    'label' => $label,
                    'detail' => $detail,
                    'url' => $url,
                ], fn (mixed $value): bool => $value !== null);
            },
            $sources,
        )));
    }

    /**
     * @param array<int, mixed> $edges
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $segmentsByKey
     * @return array<int, array{key: string, label: string, detail: string, url?: string}>
     */
    private function configuredFlowRouteSources(
        string $nodeKey,
        array $edges,
        $segmentsByKey,
    ): array {
        return collect($edges)
            ->filter(fn (mixed $edge): bool => is_array($edge)
                && ($edge['to_node_key'] ?? null) === $nodeKey
                && ($edge['role'] ?? null) === 'consequence')
            ->map(function (array $edge) use ($segmentsByKey): ?array {
                $segmentKey = $edge['segment_key'] ?? null;
                $segment = is_string($segmentKey)
                    ? $segmentsByKey->get($segmentKey)
                    : null;

                if (! is_array($segment) || ($segment['source_key'] ?? null) !== 'flow_routes') {
                    return null;
                }

                $target = collect($segment['authority']['edit_targets'] ?? [])
                    ->first(fn (mixed $candidate): bool => is_array($candidate)
                        && ($candidate['mode'] ?? null) === 'link'
                        && ($candidate['method'] ?? null) === 'GET');

                return array_filter([
                    'key' => 'flow_route:'.$segment['key'],
                    'label' => (string) ($segment['name'] ?? 'Flow Route'),
                    'detail' => trim((string) ($edge['label'] ?? '')) !== ''
                        ? (string) $edge['label']
                        : 'This Flow Route can apply the selected fact.',
                    'url' => is_array($target) ? ($target['url'] ?? null) : null,
                ], fn (mixed $value): bool => $value !== null && $value !== '');
            })
            ->filter()
            ->values()
            ->all();
    }

    private function criterionLabel(string $criterionKey): string
    {
        return match ($criterionKey) {
            'status' => 'Status',
            'tag' => 'Tag',
            default => Str::headline($criterionKey),
        };
    }

    /** @param array<string, mixed> $node */
    private function valueLabel(array $node, string $criterionKey, string $value): string
    {
        $label = trim((string) ($node['label'] ?? ''));
        $prefix = $this->criterionLabel($criterionKey).': ';

        return $label !== '' && str_starts_with($label, $prefix)
            ? Str::after($label, $prefix)
            : Str::headline($value);
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
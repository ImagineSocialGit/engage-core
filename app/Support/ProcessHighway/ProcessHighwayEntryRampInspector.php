<?php

namespace App\Support\ProcessHighway;

use App\Support\ProcessHighway\Contracts\ProcessHighwayEntryRampContributor;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
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
        $highways = collect($graph['highways'] ?? []);
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
            $processes = $this->processesForRamp(
                criterionKey: $criterionKey,
                value: $value,
                highways: $highways,
            );

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
                'process_count' => count($processes),
                'exact_process_count' => collect($processes)
                    ->where('match', 'exact')
                    ->count(),
                'partial_process_count' => collect($processes)
                    ->where('match', 'partial')
                    ->count(),
                'processes' => $processes,
            ];
        }

        $graphHighways = is_array($graph['highways'] ?? null)
            ? $graph['highways']
            : [];

        foreach ($graphHighways as &$highway) {
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

        $graph['highways'] = $graphHighways;
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
     * @param Collection<string, array<string, mixed>> $segmentsByKey
     * @return array<int, array{key: string, label: string, detail: string, url?: string}>
     */
    private function configuredFlowRouteSources(
        string $nodeKey,
        array $edges,
        Collection $segmentsByKey,
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

    /**
     * @param Collection<int, array<string, mixed>> $highways
     * @return array<int, array<string, mixed>>
     */
    private function processesForRamp(
        string $criterionKey,
        string $value,
        Collection $highways,
    ): array {
        return $highways
            ->map(function (array $highway) use ($criterionKey, $value): ?array {
                $requirements = collect($highway['entry_requirements'] ?? []);
                $matchingRequirement = $requirements->first(
                    fn (mixed $requirement): bool => is_array($requirement)
                        && ($requirement['criterion_key'] ?? null) === $criterionKey
                        && collect($requirement['values'] ?? [])
                            ->contains(fn (mixed $candidate): bool => is_array($candidate)
                                && ($candidate['value'] ?? null) === $value),
                );

                if (! is_array($matchingRequirement)) {
                    return null;
                }

                $remainingRequirements = $requirements
                    ->reject(fn (mixed $requirement): bool => is_array($requirement)
                        && ($requirement['criterion_key'] ?? null) === $criterionKey)
                    ->filter(fn (mixed $requirement): bool => is_array($requirement))
                    ->map(fn (array $requirement): array => $this->requirementSummary($requirement))
                    ->values();

                $criterionEntryNodeKeys = $requirements
                    ->flatMap(fn (mixed $requirement): array => is_array($requirement)
                        ? collect($requirement['values'] ?? [])
                            ->filter(fn (mixed $candidate): bool => is_array($candidate))
                            ->pluck('node_key')
                            ->filter(fn (mixed $key): bool => is_string($key) && $key !== '')
                            ->values()
                            ->all()
                        : [])
                    ->unique();

                foreach ($highway['entry_nodes'] ?? [] as $entryNode) {
                    if (! is_array($entryNode)
                        || $criterionEntryNodeKeys->contains($entryNode['key'] ?? null)
                    ) {
                        continue;
                    }

                    $label = $this->string($entryNode['label'] ?? null);

                    if ($label === null) {
                        continue;
                    }

                    $remainingRequirements->push([
                        'criterion_key' => 'entry',
                        'criterion_label' => 'Entry',
                        'values' => [$label],
                    ]);
                }

                if (($highway['lane_scope'] ?? null) === 'relationship') {
                    $relationshipLabel = $this->string($highway['relationship_label'] ?? null)
                        ?? Str::headline((string) ($highway['relationship_key'] ?? 'relationship'));

                    $remainingRequirements->prepend([
                        'criterion_key' => 'relationship_context',
                        'criterion_label' => 'Relationship',
                        'values' => [$relationshipLabel],
                    ]);
                }

                $match = $remainingRequirements->isEmpty()
                    ? 'exact'
                    : 'partial';

                return [
                    'key' => (string) ($highway['key'] ?? ''),
                    'name' => (string) ($highway['name'] ?? 'Configured process'),
                    'match' => $match,
                    'match_label' => $match === 'exact'
                        ? 'Exact match'
                        : 'Additional requirements',
                    'state' => (string) ($highway['state'] ?? 'configured'),
                    'state_label' => (string) ($highway['state_label'] ?? 'Configured'),
                    'remaining_requirements' => $remainingRequirements->all(),
                    'segments' => $this->processSegments($highway),
                ];
            })
            ->filter()
            ->sortBy(fn (array $process): array => [
                $process['match'] === 'exact' ? 0 : 1,
                $process['name'],
                $process['key'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $requirement
     * @return array{criterion_key: string, criterion_label: string, values: array<int, string>}
     */
    private function requirementSummary(array $requirement): array
    {
        return [
            'criterion_key' => (string) ($requirement['criterion_key'] ?? ''),
            'criterion_label' => (string) ($requirement['criterion_label'] ?? 'Requirement'),
            'values' => collect($requirement['values'] ?? [])
                ->filter(fn (mixed $candidate): bool => is_array($candidate))
                ->map(fn (array $candidate): string => (string) (
                    $candidate['label']
                    ?? $candidate['value']
                    ?? 'Configured value'
                ))
                ->filter(fn (string $label): bool => trim($label) !== '')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<string, mixed> $highway
     * @return array<int, array<string, mixed>>
     */
    private function processSegments(array $highway): array
    {
        $segments = collect($highway['segments'] ?? []);
        $attachedAcknowledgementKeys = $segments
            ->flatMap(fn (array $segment): array => array_column(
                $segment['supporting_acknowledgements'] ?? [],
                'key',
            ))
            ->filter(fn (mixed $key): bool => is_string($key))
            ->unique();

        return $segments
            ->reject(fn (array $segment): bool =>
                ($segment['attributes']['role'] ?? null) === 'reply_messaging'
                && $attachedAcknowledgementKeys->contains($segment['key'] ?? null)
            )
            ->map(function (array $segment): array {
                return [
                    'key' => (string) ($segment['key'] ?? ''),
                    'name' => (string) ($segment['name'] ?? 'Configured automation'),
                    'owner_key' => (string) ($segment['authority']['owner_key'] ?? $segment['source_key'] ?? ''),
                    'owner_label' => (string) ($segment['authority']['owner_label'] ?? Str::headline(
                        (string) ($segment['source_key'] ?? 'automation'),
                    )),
                    'state' => (string) ($segment['state'] ?? 'configured'),
                    'state_label' => (string) ($segment['state_label'] ?? 'Configured'),
                    'navigation_target' => is_array($segment['navigation_target'] ?? null)
                        ? $segment['navigation_target']
                        : null,
                    'effects' => $this->segmentEffects($segment),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $segment
     * @return array<int, array<string, mixed>>
     */
    private function segmentEffects(array $segment): array
    {
        $effects = collect();

        foreach ($segment['journey_nodes'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $effects->push([
                'key' => 'node:'.(string) ($node['key'] ?? sha1((string) json_encode($node))),
                'label' => (string) ($node['label'] ?? 'Configured step'),
                'detail' => $this->string($node['detail'] ?? null)
                    ?? $this->string($node['description'] ?? null),
                'navigation_target' => is_array($node['navigation_target'] ?? null)
                    ? $node['navigation_target']
                    : null,
            ]);

            foreach ($node['outcomes'] ?? [] as $outcome) {
                if (is_array($outcome)) {
                    $effects->push($this->outcomeEffect($outcome));
                }
            }
        }

        foreach ($segment['mechanism_outcomes'] ?? [] as $outcome) {
            if (is_array($outcome)) {
                $effects->push($this->outcomeEffect($outcome));
            }
        }

        foreach ($segment['additional_outcome_groups'] ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group['outcomes'] ?? [] as $outcome) {
                if (is_array($outcome)) {
                    $effects->push($this->outcomeEffect($outcome));
                }
            }
        }

        foreach ($segment['branch_edges'] ?? [] as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $toLabel = $this->string($edge['to_label'] ?? null);
            $edgeLabel = $this->string($edge['label'] ?? null);

            if ($toLabel === null) {
                continue;
            }

            $effects->push([
                'key' => 'edge:'.(string) ($edge['key'] ?? sha1((string) json_encode($edge))),
                'label' => trim(implode(' → ', array_filter([
                    $edgeLabel,
                    $toLabel,
                ]))),
                'detail' => null,
                'navigation_target' => is_array($edge['navigation_target'] ?? null)
                    ? $edge['navigation_target']
                    : null,
            ]);
        }

        foreach ($segment['supporting_acknowledgements'] ?? [] as $acknowledgement) {
            if (! is_array($acknowledgement)) {
                continue;
            }

            $channels = collect($acknowledgement['channels'] ?? [])
                ->filter(fn (mixed $channel): bool => is_string($channel) && trim($channel) !== '')
                ->map(fn (string $channel): string => strtoupper($channel))
                ->unique()
                ->values();

            $effects->push([
                'key' => 'acknowledgement:'.(string) (
                    $acknowledgement['key']
                    ?? sha1((string) json_encode($acknowledgement))
                ),
                'label' => (string) ($acknowledgement['name'] ?? 'Reply acknowledgement'),
                'detail' => $channels->isNotEmpty()
                    ? 'Acknowledgement on '.$channels->implode(' / ')
                    : $this->string($acknowledgement['description'] ?? null),
                'navigation_target' => is_array($acknowledgement['navigation_target'] ?? null)
                    ? $acknowledgement['navigation_target']
                    : null,
            ]);
        }

        return $effects
            ->filter(fn (mixed $effect): bool => is_array($effect)
                && trim((string) ($effect['label'] ?? '')) !== '')
            ->unique('key')
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $outcome
     * @return array<string, mixed>
     */
    private function outcomeEffect(array $outcome): array
    {
        $edge = is_array($outcome['edge'] ?? null)
            ? $outcome['edge']
            : [];
        $node = is_array($outcome['node'] ?? null)
            ? $outcome['node']
            : [];
        $edgeLabel = $this->string($edge['label'] ?? null);
        $nodeLabel = $this->string($node['label'] ?? null) ?? 'Configured outcome';

        return [
            'key' => 'outcome:'.(string) (
                $edge['key']
                ?? $node['key']
                ?? sha1((string) json_encode($outcome))
            ),
            'label' => trim(implode(' → ', array_filter([
                $edgeLabel,
                $nodeLabel,
            ]))),
            'detail' => $this->string($node['detail'] ?? null)
                ?? $this->string($node['description'] ?? null),
            'navigation_target' => is_array($node['navigation_target'] ?? null)
                ? $node['navigation_target']
                : (
                    is_array($edge['navigation_target'] ?? null)
                        ? $edge['navigation_target']
                        : null
                ),
        ];
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
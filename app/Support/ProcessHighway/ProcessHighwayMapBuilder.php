<?php

namespace App\Support\ProcessHighway;

use Illuminate\Support\Str;

final class ProcessHighwayMapBuilder
{
    /**
     * @param array<int, array<string, mixed>> $segments
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $edges
     * @param array<int, array<string, mixed>> $subjects
     * @return array<string, mixed>
     */
    public function build(
        array $segments,
        array $nodes,
        array $edges,
        array $subjects,
    ): array {
        $segmentsByKey = collect($segments)->keyBy('key');
        $nodesByKey = collect($nodes)->keyBy('key');
        $edgesByKey = collect($edges)->keyBy('key');
        $highways = [];

        foreach ($subjects as $subject) {
            foreach ($subject['lanes'] ?? [] as $lane) {
                $laneSegmentKeys = array_values(array_filter(
                    $lane['segment_keys'] ?? [],
                    fn (mixed $key): bool => is_string($key) && $segmentsByKey->has($key),
                ));

                foreach ($this->connectedComponents($laneSegmentKeys, $nodes) as $componentKeys) {
                    $highways[] = $this->highway(
                        subject: $subject,
                        lane: $lane,
                        segmentKeys: $componentKeys,
                        segmentsByKey: $segmentsByKey,
                        nodesByKey: $nodesByKey,
                        edgesByKey: $edgesByKey,
                    );
                }
            }
        }

        usort($highways, static fn (array $left, array $right): int => [
            $left['subject_label'],
            $left['lane_sort_order'],
            $left['lane_label'],
            $left['name'],
            $left['key'],
        ] <=> [
            $right['subject_label'],
            $right['lane_sort_order'],
            $right['lane_label'],
            $right['name'],
            $right['key'],
        ]);

        $subjects = $this->attachHighwayMembership($subjects, $highways);

        return [
            'subjects' => $subjects,
            'highways' => $highways,
            'highway_count' => count($highways),
            'qualifier_filters' => $this->qualifierFilters($highways),
        ];
    }

    /**
     * @param array<int, string> $segmentKeys
     * @param array<int, array<string, mixed>> $nodes
     * @return array<int, array<int, string>>
     */
    private function connectedComponents(array $segmentKeys, array $nodes): array
    {
        sort($segmentKeys);
        $allowed = array_fill_keys($segmentKeys, true);
        $adjacency = array_fill_keys($segmentKeys, []);

        foreach ($nodes as $node) {
            $members = array_values(array_unique(array_filter(
                $node['segment_keys'] ?? [],
                fn (mixed $key): bool => is_string($key) && isset($allowed[$key]),
            )));

            for ($left = 0; $left < count($members); $left++) {
                for ($right = $left + 1; $right < count($members); $right++) {
                    $adjacency[$members[$left]][$members[$right]] = true;
                    $adjacency[$members[$right]][$members[$left]] = true;
                }
            }
        }

        $visited = [];
        $components = [];

        foreach ($segmentKeys as $segmentKey) {
            if (isset($visited[$segmentKey])) {
                continue;
            }

            $queue = [$segmentKey];
            $visited[$segmentKey] = true;
            $component = [];

            while ($queue !== []) {
                $current = array_shift($queue);

                if (! is_string($current)) {
                    continue;
                }

                $component[] = $current;
                $neighbors = array_keys($adjacency[$current] ?? []);
                sort($neighbors);

                foreach ($neighbors as $neighbor) {
                    if (isset($visited[$neighbor])) {
                        continue;
                    }

                    $visited[$neighbor] = true;
                    $queue[] = $neighbor;
                }
            }

            sort($component);
            $components[] = $component;
        }

        return $components;
    }

    /**
     * @param array<string, mixed> $subject
     * @param array<string, mixed> $lane
     * @param array<int, string> $segmentKeys
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $segmentsByKey
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $nodesByKey
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $edgesByKey
     * @return array<string, mixed>
     */
    private function highway(
        array $subject,
        array $lane,
        array $segmentKeys,
        $segmentsByKey,
        $nodesByKey,
        $edgesByKey,
    ): array {
        $segments = collect($segmentKeys)
            ->map(fn (string $key): ?array => $segmentsByKey->get($key))
            ->filter(fn (mixed $segment): bool => is_array($segment))
            ->sortBy(fn (array $segment): array => [
                $segment['sort_order'] ?? 100,
                $segment['name'] ?? '',
                $segment['key'] ?? '',
            ])
            ->values();

        $orderedSegmentKeys = $segments->pluck('key')->values()->all();
        $nodeKeys = $segments
            ->flatMap(fn (array $segment): array => $segment['node_keys'] ?? [])
            ->filter(fn (mixed $key): bool => is_string($key) && $nodesByKey->has($key))
            ->unique()
            ->values();
        $edgeKeys = $segments
            ->flatMap(fn (array $segment): array => $segment['edge_keys'] ?? [])
            ->filter(fn (mixed $key): bool => is_string($key) && $edgesByKey->has($key))
            ->unique()
            ->values();
        $nodes = $nodeKeys
            ->map(function (string $key) use ($nodesByKey, $orderedSegmentKeys, $segmentsByKey): array {
                $node = $nodesByKey->get($key);
                $node['segment_keys'] = array_values(array_intersect(
                    $node['segment_keys'] ?? [],
                    $orderedSegmentKeys,
                ));

                return $this->decorateWithSegmentFallback(
                    element: $node,
                    segmentKeys: $node['segment_keys'],
                    segmentsByKey: $segmentsByKey,
                );
            })
            ->values();
        $componentNodesByKey = $nodes->keyBy('key');
        $edges = $edgeKeys
            ->map(function (string $key) use ($edgesByKey, $componentNodesByKey, $segmentsByKey): array {
                $edge = $edgesByKey->get($key);
                $edge = $this->decorateWithSegmentFallback(
                    element: $edge,
                    segmentKeys: is_string($edge['segment_key'] ?? null)
                        ? [$edge['segment_key']]
                        : [],
                    segmentsByKey: $segmentsByKey,
                );
                $edge['from_label'] = $componentNodesByKey->get($edge['from_node_key'])['label'] ?? null;
                $edge['to_label'] = $componentNodesByKey->get($edge['to_node_key'])['label'] ?? null;

                return $edge;
            })
            ->values();
        $componentEdgesByKey = $edges->keyBy('key');
        $candidateEntryKeys = $segments
            ->flatMap(fn (array $segment): array => $segment['entry_node_keys'] ?? [])
            ->filter(fn (mixed $key): bool => is_string($key) && $componentNodesByKey->has($key))
            ->unique()
            ->values();
        $candidateExitKeys = $segments
            ->flatMap(fn (array $segment): array => $segment['exit_node_keys'] ?? [])
            ->filter(fn (mixed $key): bool => is_string($key) && $componentNodesByKey->has($key))
            ->unique()
            ->values();
        $outgoingNodeKeys = $edges
            ->pluck('from_node_key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->unique();
        $incomingNodeKeys = $edges
            ->pluck('to_node_key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->unique();
        $entryNodeKeys = $candidateEntryKeys
            ->reject(fn (string $key): bool => $incomingNodeKeys->contains($key))
            ->values();

        if ($entryNodeKeys->isEmpty()) {
            $entryNodeKeys = $candidateEntryKeys;
        }

        $terminalExitKeys = $candidateExitKeys
            ->reject(fn (string $key): bool => $outgoingNodeKeys->contains($key))
            ->values();

        if ($terminalExitKeys->isEmpty()) {
            $terminalExitKeys = $candidateExitKeys;
        }

        $entryNodes = $entryNodeKeys
            ->map(fn (string $key): ?array => $componentNodesByKey->get($key))
            ->filter(fn (mixed $node): bool => is_array($node))
            ->values();
        $exitNodes = $terminalExitKeys
            ->map(fn (string $key): ?array => $componentNodesByKey->get($key))
            ->filter(fn (mixed $node): bool => is_array($node))
            ->values();
        $qualifiers = $this->highwayQualifiers($nodes);
        $decoratedSegments = $segments
            ->map(fn (array $segment): array => $this->decorateSegment(
                segment: $segment,
                nodesByKey: $componentNodesByKey,
                edgesByKey: $componentEdgesByKey,
            ))
            ->values()
            ->all();
        $name = $this->highwayName($entryNodes->all(), $segments->all());
        $key = ($lane['key'] ?? 'lane').':highway:'.substr(
            sha1(implode('|', $orderedSegmentKeys)),
            0,
            12,
        );
        $sourceKeys = $segments
            ->pluck('source_key')
            ->filter(fn (mixed $source): bool => is_string($source) && $source !== '')
            ->unique()
            ->values();
        $sourceLabels = $segments
            ->map(fn (array $segment): ?string => $segment['authority']['owner_label'] ?? null)
            ->filter(fn (mixed $label): bool => is_string($label) && $label !== '')
            ->unique()
            ->values();
        $state = $nodes->contains(
            fn (array $node): bool => ($node['state'] ?? null) === 'needs_configuration',
        ) || $segments->contains(
            fn (array $segment): bool => ($segment['state'] ?? null) === 'needs_configuration',
        )
            ? 'needs_configuration'
            : ($segments->contains(
                fn (array $segment): bool => ($segment['state'] ?? null) === 'active',
            ) ? 'active' : 'off');

        return [
            'key' => $key,
            'name' => $name,
            'subject_key' => $subject['key'],
            'subject_label' => $subject['label'],
            'lane_key' => $lane['key'],
            'lane_label' => $lane['label'],
            'lane_scope' => $lane['scope'],
            'lane_sort_order' => $lane['sort_order'] ?? 100,
            'relationship_key' => $lane['relationship_key'] ?? null,
            'relationship_label' => $lane['relationship_label'] ?? null,
            'state' => $state,
            'state_label' => match ($state) {
                'active' => 'Active',
                'needs_configuration' => 'Needs configuration',
                default => 'Off',
            },
            'segment_keys' => $orderedSegmentKeys,
            'segment_count' => count($decoratedSegments),
            'source_keys' => $sourceKeys->all(),
            'source_labels' => $sourceLabels->all(),
            'source_count' => $sourceKeys->count(),
            'node_keys' => $nodeKeys->all(),
            'node_count' => $nodeKeys->count(),
            'edge_keys' => $edgeKeys->all(),
            'edge_count' => $edgeKeys->count(),
            'entry_node_keys' => $entryNodeKeys->all(),
            'entry_nodes' => $entryNodes->all(),
            'exit_node_keys' => $terminalExitKeys->all(),
            'exit_nodes' => $exitNodes->all(),
            'qualifiers' => $qualifiers,
            'segments' => $decoratedSegments,
            'search_text' => Str::lower(implode(' ', array_filter([
                $name,
                $subject['label'] ?? null,
                $lane['label'] ?? null,
                ...$sourceLabels->all(),
                ...$nodes->pluck('label')->all(),
                ...$segments->pluck('name')->all(),
                ...$segments->pluck('description')->all(),
            ], fn (mixed $value): bool => is_string($value) && $value !== ''))),
        ];
    }

    /**
     * @param array<string, mixed> $segment
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $nodesByKey
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $edgesByKey
     * @return array<string, mixed>
     */
    private function decorateSegment(array $segment, $nodesByKey, $edgesByKey): array
    {
        $entryKeys = collect($segment['entry_node_keys'] ?? []);
        $exitKeys = collect($segment['exit_node_keys'] ?? []);
        $mechanismNodeKey = $segment['mechanism_node_key'] ?? null;
        $segmentNodes = collect($segment['node_keys'] ?? [])
            ->map(fn (mixed $key): ?array => is_string($key) ? $nodesByKey->get($key) : null)
            ->filter(fn (mixed $node): bool => is_array($node))
            ->values();
        $segmentEdges = collect($segment['edge_keys'] ?? [])
            ->map(fn (mixed $key): ?array => is_string($key) ? $edgesByKey->get($key) : null)
            ->filter(fn (mixed $edge): bool => is_array($edge))
            ->values();

        return [
            ...$this->decorateAuthorityTarget($segment),
            'mechanism_node' => is_string($mechanismNodeKey)
                ? $nodesByKey->get($mechanismNodeKey)
                : null,
            'entry_nodes' => $segmentNodes
                ->filter(fn (array $node): bool => $entryKeys->contains($node['key']))
                ->values()
                ->all(),
            'journey_nodes' => $segmentNodes
                ->reject(fn (array $node): bool => $node['key'] === $mechanismNodeKey)
                ->reject(fn (array $node): bool => $entryKeys->contains($node['key']))
                ->reject(fn (array $node): bool => $exitKeys->contains($node['key']))
                ->values()
                ->all(),
            'exit_nodes' => $segmentNodes
                ->filter(fn (array $node): bool => $exitKeys->contains($node['key']))
                ->values()
                ->all(),
            'edges' => $segmentEdges->all(),
            'branch_edges' => $segmentEdges
                ->filter(fn (array $edge): bool => ($edge['role'] ?? null) === 'branch'
                    && ($edge['attributes']['operator'] ?? null) !== 'or')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $entryNodes
     * @param array<int, array<string, mixed>> $segments
     */
    private function highwayName(array $entryNodes, array $segments): string
    {
        $labels = collect($entryNodes)
            ->pluck('label')
            ->filter(fn (mixed $label): bool => is_string($label) && trim($label) !== '')
            ->map(fn (string $label): string => preg_replace(
                '/^(Status|Tag|Source|Subsource|Webinar outcome):\s*/i',
                '',
                $label,
            ) ?? $label)
            ->unique()
            ->values();

        if ($labels->isEmpty()) {
            return (string) ($segments[0]['name'] ?? 'Configured process');
        }

        if ($labels->count() <= 2) {
            return Str::limit($labels->implode(' + '), 90);
        }

        return Str::limit(
            $labels->take(2)->implode(' + ').' + '.($labels->count() - 2).' more entrances',
            90,
        );
    }

    /**
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $nodes
     * @return array<string, array<int, string>>
     */
    private function highwayQualifiers($nodes): array
    {
        $qualifiers = [];

        foreach ($nodes as $node) {
            $criterionKey = $node['attributes']['criterion_key'] ?? null;
            $value = $node['attributes']['value'] ?? null;

            if (! is_string($criterionKey) || $criterionKey === '' || ! is_string($value) || $value === '') {
                continue;
            }

            $qualifiers[$criterionKey][] = $value;
        }

        foreach ($qualifiers as $criterionKey => $values) {
            $qualifiers[$criterionKey] = array_values(array_unique($values));
            sort($qualifiers[$criterionKey]);
        }

        ksort($qualifiers);

        return $qualifiers;
    }

    /**
     * @param array<int, array<string, mixed>> $highways
     * @return array<int, array<string, mixed>>
     */
    private function qualifierFilters(array $highways): array
    {
        $filters = [];
        $priorities = [
            'status' => 10,
            'tag' => 20,
            'relationship' => 30,
            'webinar_outcome' => 40,
            'source' => 50,
            'subsource' => 60,
        ];

        foreach ($highways as $highway) {
            foreach ($highway['segments'] as $segment) {
                foreach ([...$segment['entry_nodes'], ...$segment['journey_nodes']] as $node) {
                    $criterionKey = $node['attributes']['criterion_key'] ?? null;
                    $value = $node['attributes']['value'] ?? null;

                    if (! is_string($criterionKey) || $criterionKey === '' || ! is_string($value) || $value === '') {
                        continue;
                    }

                    $filters[$criterionKey] ??= [
                        'key' => $criterionKey,
                        'label' => $this->criterionLabel($criterionKey),
                        'priority' => $priorities[$criterionKey] ?? 100,
                        'options' => [],
                    ];
                    $filters[$criterionKey]['options'][$value] ??= [
                        'value' => $value,
                        'label' => $node['attributes']['value_label']
                            ?? $this->qualifierValueLabel($node, $criterionKey, $value),
                        'highway_keys' => [],
                    ];
                    $filters[$criterionKey]['options'][$value]['highway_keys'][] = $highway['key'];
                }
            }
        }

        foreach ($filters as &$filter) {
            $options = array_values($filter['options']);

            foreach ($options as &$option) {
                $option['highway_keys'] = array_values(array_unique($option['highway_keys']));
            }
            unset($option);

            usort($options, static fn (array $left, array $right): int => [
                $left['label'],
                $left['value'],
            ] <=> [
                $right['label'],
                $right['value'],
            ]);
            $filter['options'] = $options;
        }
        unset($filter);

        uasort($filters, static fn (array $left, array $right): int => [
            $left['priority'],
            $left['label'],
        ] <=> [
            $right['priority'],
            $right['label'],
        ]);

        return array_values($filters);
    }

    private function criterionLabel(string $criterionKey): string
    {
        return match ($criterionKey) {
            'status' => 'Status',
            'tag' => 'Tag',
            'relationship' => 'Relationship',
            'webinar_outcome' => 'Webinar outcome',
            'source' => 'Source',
            'subsource' => 'Subsource',
            default => Str::headline($criterionKey),
        };
    }

    /** @param array<string, mixed> $node */
    private function qualifierValueLabel(array $node, string $criterionKey, string $value): string
    {
        $label = trim((string) ($node['label'] ?? ''));
        $prefix = $this->criterionLabel($criterionKey).': ';

        if ($label !== '' && str_starts_with($label, $prefix)) {
            return Str::after($label, $prefix);
        }

        return Str::headline($value);
    }

    /**
     * @param array<int, array<string, mixed>> $subjects
     * @param array<int, array<string, mixed>> $highways
     * @return array<int, array<string, mixed>>
     */
    private function attachHighwayMembership(array $subjects, array $highways): array
    {
        $membership = [];

        foreach ($highways as $highway) {
            $membership[$highway['lane_key']][] = $highway['key'];
        }

        foreach ($subjects as &$subject) {
            foreach ($subject['lanes'] as &$lane) {
                $lane['highway_keys'] = array_values($membership[$lane['key']] ?? []);
                $lane['highway_count'] = count($lane['highway_keys']);
            }
            unset($lane);
        }
        unset($subject);

        return $subjects;
    }

    /** @param array<string, mixed> $element */
    private function decorateAuthorityTarget(array $element): array
    {
        $targets = collect($element['authority']['edit_targets'] ?? [])
            ->filter(fn (mixed $target): bool => is_array($target)
                && ($target['mode'] ?? null) === 'link'
                && ($target['method'] ?? null) === 'GET');
        $ownerKey = $element['authority']['owner_key'] ?? null;
        $element['navigation_target'] = $targets->first(
            fn (array $target): bool => is_string($ownerKey)
                && ($target['owner_key'] ?? null) === $ownerKey,
        ) ?? $targets->first();

        return $element;
    }

    /**
     * @param array<string, mixed> $element
     * @param array<int, string> $segmentKeys
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $segmentsByKey
     * @return array<string, mixed>
     */
    private function decorateWithSegmentFallback(
        array $element,
        array $segmentKeys,
        $segmentsByKey,
    ): array {
        $element = $this->decorateAuthorityTarget($element);

        if (is_array($element['navigation_target'])) {
            return $element;
        }

        foreach ($segmentKeys as $segmentKey) {
            $segment = $segmentsByKey->get($segmentKey);

            if (! is_array($segment)) {
                continue;
            }

            $segment = $this->decorateAuthorityTarget($segment);

            if (is_array($segment['navigation_target'])) {
                $element['navigation_target'] = $segment['navigation_target'];

                break;
            }
        }

        return $element;
    }
}
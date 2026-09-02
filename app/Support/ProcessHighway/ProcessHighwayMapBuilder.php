<?php

namespace App\Support\ProcessHighway;

use Illuminate\Support\Str;

final class ProcessHighwayMapBuilder
{
    public function __construct(
        private readonly ProcessHighwayExitLinkBuilder $exitLinks,
    ) {}

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

                foreach ($this->rootedHighways(
                    segmentKeys: $laneSegmentKeys,
                    segmentsByKey: $segmentsByKey,
                    nodesByKey: $nodesByKey,
                    edgesByKey: $edgesByKey,
                ) as $rootedHighway) {
                    $highways[] = $this->highway(
                        subject: $subject,
                        lane: $lane,
                        rootSegmentKeys: $rootedHighway['root_segment_keys'],
                        segmentKeys: $rootedHighway['segment_keys'],
                        segmentConnections: $rootedHighway['segment_connections'],
                        segmentHandoffs: $rootedHighway['segment_handoffs'],
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
            'qualifier_filters' => $this->qualifierFilters(
                highways: $highways,
                nodes: $nodes,
                edges: $edges,
            ),
        ];
    }

    /**
     * @param array<int, string> $segmentKeys
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $segmentsByKey
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $nodesByKey
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $edgesByKey
     * @return array<int, array{
     *     root_segment_keys: array<int, string>,
     *     segment_keys: array<int, string>,
     *     segment_connections: array<int, array{from_segment_key: string, to_segment_key: string}>,
     *     segment_handoffs: array<int, array{from_segment_key: string, to_segment_key: string, via_node_keys: array<int, string>}>
     * }>
     */
    private function rootedHighways(
        array $segmentKeys,
        $segmentsByKey,
        $nodesByKey,
        $edgesByKey,
    ): array {
        sort($segmentKeys);
        $allowed = array_fill_keys($segmentKeys, true);
        $adjacency = array_fill_keys($segmentKeys, []);
        $incoming = array_fill_keys($segmentKeys, []);
        $handoffs = array_fill_keys($segmentKeys, []);
        $entryMembership = [];
        $mechanismMembership = [];

        foreach ($segmentKeys as $segmentKey) {
            $segment = $segmentsByKey->get($segmentKey);

            if (! is_array($segment)) {
                continue;
            }

            foreach ($segment['entry_node_keys'] ?? [] as $entryNodeKey) {
                if (is_string($entryNodeKey) && $entryNodeKey !== '') {
                    $entryMembership[$entryNodeKey][] = $segmentKey;
                }
            }

            $mechanismNodeKey = $segment['mechanism_node_key'] ?? null;

            if (is_string($mechanismNodeKey) && $mechanismNodeKey !== '') {
                $mechanismMembership[$mechanismNodeKey][] = $segmentKey;
            }
        }

        foreach ($edgesByKey as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $producerKey = $edge['segment_key'] ?? null;
            $targetNodeKey = $edge['to_node_key'] ?? null;

            if (! is_string($producerKey) || ! isset($allowed[$producerKey])
                || ! is_string($targetNodeKey) || $targetNodeKey === ''
            ) {
                continue;
            }

            $targetNode = $nodesByKey->get($targetNodeKey);
            $isContactFact = is_array($targetNode)
                && is_string($targetNode['attributes']['criterion_key'] ?? null)
                && $targetNode['attributes']['criterion_key'] !== '';

            if (! $isContactFact) {
                foreach ($entryMembership[$targetNodeKey] ?? [] as $consumerKey) {
                    $this->connectDownstream(
                        adjacency: $adjacency,
                        incoming: $incoming,
                        handoffs: $handoffs,
                        producerKey: $producerKey,
                        consumerKey: $consumerKey,
                        viaNodeKey: $targetNodeKey,
                    );
                }
            }

            if (($edge['role'] ?? null) === 'exits_to') {
                foreach ($mechanismMembership[$targetNodeKey] ?? [] as $consumerKey) {
                    $this->connectDownstream(
                        adjacency: $adjacency,
                        incoming: $incoming,
                        handoffs: $handoffs,
                        producerKey: $producerKey,
                        consumerKey: $consumerKey,
                        viaNodeKey: $targetNodeKey,
                    );
                }
            }
        }

        $rootKeys = array_values(array_filter(
            $segmentKeys,
            fn (string $segmentKey): bool => ($incoming[$segmentKey] ?? []) === [],
        ));

        if ($rootKeys === []) {
            $rootKeys = $segmentKeys;
        }

        $rootGroups = [];

        foreach ($rootKeys as $rootKey) {
            $segment = $segmentsByKey->get($rootKey);

            if (! is_array($segment)) {
                continue;
            }

            $rootGroups[$this->entrySignature($segment, $nodesByKey)][] = $rootKey;
        }

        $highways = [];

        foreach ($rootGroups as $groupRootKeys) {
            sort($groupRootKeys);
            $included = array_fill_keys($groupRootKeys, true);
            $queue = $groupRootKeys;

            while ($queue !== []) {
                $current = array_shift($queue);

                if (! is_string($current)) {
                    continue;
                }

                $downstreamKeys = array_keys($adjacency[$current] ?? []);
                sort($downstreamKeys);

                foreach ($downstreamKeys as $downstreamKey) {
                    if (isset($included[$downstreamKey])) {
                        continue;
                    }

                    $included[$downstreamKey] = true;
                    $queue[] = $downstreamKey;
                }
            }

            $includedKeys = array_keys($included);
            sort($includedKeys);
            $segmentConnections = collect($includedKeys)
                ->flatMap(function (string $producerKey) use ($adjacency, $included): array {
                    return collect(array_keys($adjacency[$producerKey] ?? []))
                        ->filter(fn (string $consumerKey): bool => isset($included[$consumerKey]))
                        ->map(fn (string $consumerKey): array => [
                            'from_segment_key' => $producerKey,
                            'to_segment_key' => $consumerKey,
                        ])
                        ->all();
                })
                ->sortBy(fn (array $connection): array => [
                    $connection['from_segment_key'],
                    $connection['to_segment_key'],
                ])
                ->values()
                ->all();
            $segmentHandoffs = collect($segmentConnections)
                ->map(function (array $connection) use ($handoffs): array {
                    $producerKey = $connection['from_segment_key'];
                    $consumerKey = $connection['to_segment_key'];
                    $viaNodeKeys = array_keys($handoffs[$producerKey][$consumerKey] ?? []);
                    sort($viaNodeKeys);

                    return [
                        ...$connection,
                        'via_node_keys' => $viaNodeKeys,
                    ];
                })
                ->values()
                ->all();
            $highways[] = [
                'root_segment_keys' => $groupRootKeys,
                'segment_keys' => $includedKeys,
                'segment_connections' => $segmentConnections,
                'segment_handoffs' => $segmentHandoffs,
            ];
        }

        return $highways;
    }

    /**
     * @param array<string, mixed> $segment
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $nodesByKey
     */
    private function entrySignature(array $segment, $nodesByKey): string
    {
        $requirements = $this->entryRequirements(collect([$segment]), $nodesByKey);
        $criterionNodeKeys = collect($requirements)
            ->flatMap(fn (array $requirement): array => array_column($requirement['values'], 'node_key'))
            ->filter(fn (mixed $key): bool => is_string($key))
            ->all();
        $otherEntryKeys = collect($segment['entry_node_keys'] ?? [])
            ->filter(fn (mixed $key): bool => is_string($key) && ! in_array($key, $criterionNodeKeys, true))
            ->sort()
            ->values()
            ->all();
        $normalizedRequirements = collect($requirements)
            ->map(fn (array $requirement): array => [
                'criterion_key' => $requirement['criterion_key'],
                'values' => collect($requirement['values'])
                    ->pluck('value')
                    ->sort()
                    ->values()
                    ->all(),
            ])
            ->sortBy('criterion_key')
            ->values()
            ->all();

        return sha1((string) json_encode([
            'requirements' => $normalizedRequirements,
            'other_entry_keys' => $otherEntryKeys,
        ]));
    }

    /**
     * @param array<string, array<string, bool>> $adjacency
     * @param array<string, array<string, bool>> $incoming
     * @param array<string, array<string, array<string, bool>>> $handoffs
     */
    private function connectDownstream(
        array &$adjacency,
        array &$incoming,
        array &$handoffs,
        string $producerKey,
        string $consumerKey,
        string $viaNodeKey,
    ): void {
        if (
            $producerKey === $consumerKey
            || ! isset($adjacency[$producerKey])
            || ! isset($adjacency[$consumerKey])
        ) {
            return;
        }

        $adjacency[$producerKey][$consumerKey] = true;
        $incoming[$consumerKey][$producerKey] = true;
        $handoffs[$producerKey][$consumerKey][$viaNodeKey] = true;
    }

    /**
     * @param array<string, mixed> $subject
     * @param array<string, mixed> $lane
     * @param array<int, string> $rootSegmentKeys
     * @param array<int, string> $segmentKeys
     * @param array<int, array{from_segment_key: string, to_segment_key: string}> $segmentConnections
     * @param array<int, array{from_segment_key: string, to_segment_key: string, via_node_keys: array<int, string>}> $segmentHandoffs
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $segmentsByKey
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $nodesByKey
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $edgesByKey
     * @return array<string, mixed>
     */
    private function highway(
        array $subject,
        array $lane,
        array $rootSegmentKeys,
        array $segmentKeys,
        array $segmentConnections,
        array $segmentHandoffs,
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
        $segmentConnections = collect($segmentConnections)
            ->filter(fn (array $connection): bool => in_array(
                $connection['from_segment_key'] ?? null,
                $orderedSegmentKeys,
                true,
            ) && in_array(
                $connection['to_segment_key'] ?? null,
                $orderedSegmentKeys,
                true,
            ))
            ->values()
            ->all();
        $segmentHandoffs = collect($segmentHandoffs)
            ->filter(fn (array $handoff): bool => in_array(
                $handoff['from_segment_key'] ?? null,
                $orderedSegmentKeys,
                true,
            ) && in_array(
                $handoff['to_segment_key'] ?? null,
                $orderedSegmentKeys,
                true,
            ))
            ->values()
            ->all();
        $segmentStages = $this->segmentStages(
            segmentKeys: $orderedSegmentKeys,
            connections: $segmentConnections,
        );
        $nonTerminalSegmentKeys = collect($segmentConnections)
            ->pluck('from_segment_key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->unique();
        $terminalSegmentKeys = collect($orderedSegmentKeys)
            ->reject(fn (string $key): bool => $nonTerminalSegmentKeys->contains($key))
            ->values()
            ->all();
        $rootSegments = collect($rootSegmentKeys)
            ->map(fn (string $key): ?array => $segmentsByKey->get($key))
            ->filter(fn (mixed $segment): bool => is_array($segment))
            ->values();
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
        $candidateEntryKeys = $rootSegments
            ->flatMap(fn (array $segment): array => $segment['entry_node_keys'] ?? [])
            ->filter(fn (mixed $key): bool => is_string($key) && $componentNodesByKey->has($key))
            ->unique()
            ->values();
        $candidateExitKeys = $segments
            ->flatMap(fn (array $segment): array => $segment['exit_node_keys'] ?? [])
            ->filter(fn (mixed $key): bool => is_string($key) && $componentNodesByKey->has($key))
            ->filter(fn (string $key): bool => $this->isVisible($componentNodesByKey->get($key)))
            ->unique()
            ->values();
        $outgoingNodeKeys = $edges
            ->pluck('from_node_key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->unique();
        $entryNodeKeys = $candidateEntryKeys;

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
        $entryRequirements = $this->entryRequirements($rootSegments, $componentNodesByKey);
        $qualifiers = $this->requirementsQualifiers($entryRequirements);
        $key = ($lane['key'] ?? 'lane').':highway:'.substr(
            sha1(implode('|', $rootSegmentKeys)),
            0,
            12,
        );
        $decoratedSegments = $segments
            ->map(fn (array $segment): array => $this->decorateSegment(
                segment: $segment,
                nodesByKey: $componentNodesByKey,
                edgesByKey: $componentEdgesByKey,
                highwayKey: $key,
            ))
            ->values();
        $acknowledgementSegments = $decoratedSegments
            ->filter(fn (array $segment): bool => ($segment['attributes']['role'] ?? null) === 'reply_messaging')
            ->values();
        $decoratedSegments = $decoratedSegments
            ->map(function (array $segment) use ($acknowledgementSegments): array {
                $segment['supporting_acknowledgements'] = [];

                if (($segment['attributes']['role'] ?? null) !== 'reply_routing') {
                    return $segment;
                }

                $profileKeys = $segment['attributes']['reply_profile_keys'] ?? [];
                $intentKeys = $segment['attributes']['reply_intent_keys'] ?? [];
                $segment['supporting_acknowledgements'] = $acknowledgementSegments
                    ->filter(function (array $acknowledgement) use ($profileKeys, $intentKeys): bool {
                        $acknowledgementProfiles = $acknowledgement['attributes']['reply_profile_keys'] ?? [];
                        $acknowledgementIntents = $acknowledgement['attributes']['reply_intent_keys'] ?? [];

                        return array_intersect($profileKeys, $acknowledgementProfiles) !== []
                            && ($intentKeys === []
                                || $acknowledgementIntents === []
                                || array_intersect($intentKeys, $acknowledgementIntents) !== []);
                    })
                    ->map(fn (array $acknowledgement): array => [
                        'key' => $acknowledgement['key'],
                        'name' => $acknowledgement['name'],
                        'description' => $acknowledgement['description'],
                        'channels' => $acknowledgement['attributes']['reply_channels'] ?? [],
                        'navigation_target' => $acknowledgement['navigation_target'],
                    ])
                    ->values()
                    ->all();

                return $segment;
            })
            ->values()
            ->all();
        $junctions = $this->replyJunctions(
            highwayKey: $key,
            stages: $segmentStages,
            handoffs: $segmentHandoffs,
            segmentsByKey: collect($decoratedSegments)->keyBy('key'),
            nodesByKey: $componentNodesByKey,
        );
        $name = $this->highwayName(
            entryRequirements: $entryRequirements,
            entryNodes: $entryNodes->all(),
            segments: $rootSegments->all(),
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
            'root_segment_keys' => array_values($rootSegmentKeys),
            'segment_keys' => $orderedSegmentKeys,
            'segment_connections' => $segmentConnections,
            'segment_handoffs' => $segmentHandoffs,
            'segment_stages' => $segmentStages,
            'junctions' => $junctions,
            'terminal_segment_keys' => $terminalSegmentKeys,
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
            'entry_operator' => 'all',
            'entry_requirements' => $entryRequirements,
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
     * @param array<int, string> $segmentKeys
     * @param array<int, array{from_segment_key: string, to_segment_key: string}> $connections
     * @return array<int, array<int, string>>
     */
    private function segmentStages(array $segmentKeys, array $connections): array
    {
        $order = array_flip($segmentKeys);
        $remaining = array_fill_keys($segmentKeys, true);
        $incomingCount = array_fill_keys($segmentKeys, 0);
        $outgoing = array_fill_keys($segmentKeys, []);

        foreach ($connections as $connection) {
            $from = $connection['from_segment_key'] ?? null;
            $to = $connection['to_segment_key'] ?? null;

            if (! is_string($from) || ! is_string($to)
                || ! isset($remaining[$from]) || ! isset($remaining[$to])
                || isset($outgoing[$from][$to])
            ) {
                continue;
            }

            $outgoing[$from][$to] = true;
            $incomingCount[$to]++;
        }

        $stages = [];

        while ($remaining !== []) {
            $stage = array_values(array_filter(
                array_keys($remaining),
                fn (string $key): bool => ($incomingCount[$key] ?? 0) === 0,
            ));

            if ($stage === []) {
                $stage = array_keys($remaining);
            }

            usort($stage, fn (string $left, string $right): int =>
                ($order[$left] ?? PHP_INT_MAX) <=> ($order[$right] ?? PHP_INT_MAX));
            $stages[] = $stage;

            foreach ($stage as $segmentKey) {
                unset($remaining[$segmentKey]);

                foreach (array_keys($outgoing[$segmentKey] ?? []) as $downstreamKey) {
                    $incomingCount[$downstreamKey] = max(
                        0,
                        ($incomingCount[$downstreamKey] ?? 0) - 1,
                    );
                }
            }
        }

        return $stages;
    }

    /**
     * @param array<int, array<int, string>> $stages
     * @param array<int, array{from_segment_key: string, to_segment_key: string, via_node_keys: array<int, string>}> $handoffs
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $segmentsByKey
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $nodesByKey
     * @return array<int, array<string, mixed>>
     */
    private function replyJunctions(
        string $highwayKey,
        array $stages,
        array $handoffs,
        $segmentsByKey,
        $nodesByKey,
    ): array {
        $stageBySegment = [];

        foreach ($stages as $stageIndex => $stage) {
            foreach ($stage as $segmentKey) {
                $stageBySegment[$segmentKey] = $stageIndex;
            }
        }

        $junctions = [];

        foreach ($handoffs as $handoff) {
            $fromSegmentKey = $handoff['from_segment_key'] ?? null;
            $toSegmentKey = $handoff['to_segment_key'] ?? null;

            if (! is_string($fromSegmentKey) || ! is_string($toSegmentKey)) {
                continue;
            }

            $fromSegment = $segmentsByKey->get($fromSegmentKey);
            $toSegment = $segmentsByKey->get($toSegmentKey);

            if (! is_array($fromSegment) || ! is_array($toSegment)
                || ($toSegment['source_key'] ?? null) !== 'flow_routes'
            ) {
                continue;
            }

            foreach ($handoff['via_node_keys'] ?? [] as $viaNodeKey) {
                $node = is_string($viaNodeKey) ? $nodesByKey->get($viaNodeKey) : null;

                if (! is_array($node)
                    || ($node['attributes']['event_key'] ?? null) !== 'inbound_message.normal_reply'
                ) {
                    continue;
                }

                $junctionKey = $highwayKey.':reply-junction:'.substr(
                    sha1((string) $viaNodeKey),
                    0,
                    12,
                );
                $junctions[$junctionKey] ??= [
                    'key' => $junctionKey,
                    'node_key' => $viaNodeKey,
                    'label' => 'Reply received',
                    'description' => 'Inbound Messaging classifies the reply before any matching automation runs.',
                    'source_key' => 'inbound_messaging',
                    'before_stage_index' => $stageBySegment[$toSegmentKey] ?? 0,
                    'navigation_target' => $node['navigation_target'] ?? null,
                    'from_segment_keys' => [],
                    'branch_segment_keys' => [],
                    'has_catch_all_branch' => false,
                    'alternative_path' => [
                        'label' => 'Other reply',
                        'description' => 'No configured reply Route matches this message.',
                        'inbox_review' => true,
                        'team_notification_available' => in_array(
                            'internal_notifications',
                            (array) config('modules.enabled', []),
                            true,
                        ),
                        'campaign_continues' => true,
                        'recommendation' => 'Consider pausing or stopping the campaign when another reply is received.',
                    ],
                ];
                $junctions[$junctionKey]['before_stage_index'] = min(
                    $junctions[$junctionKey]['before_stage_index'],
                    $stageBySegment[$toSegmentKey] ?? 0,
                );
                $junctions[$junctionKey]['from_segment_keys'][] = $fromSegmentKey;
                $junctions[$junctionKey]['branch_segment_keys'][] = $toSegmentKey;
                $junctions[$junctionKey]['has_catch_all_branch'] =
                    $junctions[$junctionKey]['has_catch_all_branch']
                    || ($toSegment['attributes']['reply_intent_keys'] ?? []) === [];
            }
        }

        return collect($junctions)
            ->map(function (array $junction): array {
                $junction['from_segment_keys'] = array_values(array_unique(
                    $junction['from_segment_keys'],
                ));
                $junction['branch_segment_keys'] = array_values(array_unique(
                    $junction['branch_segment_keys'],
                ));

                if ($junction['has_catch_all_branch']) {
                    $junction['alternative_path'] = null;
                }

                return $junction;
            })
            ->sortBy(fn (array $junction): array => [
                $junction['before_stage_index'],
                $junction['node_key'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $segment
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $nodesByKey
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $edgesByKey
     * @return array<string, mixed>
     */
    private function decorateSegment(
        array $segment,
        $nodesByKey,
        $edgesByKey,
        string $highwayKey,
    ): array {
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
        $visibleSegmentNodes = $segmentNodes
            ->filter(fn (array $node): bool => $this->isVisible($node))
            ->values();
        $visibleNodeKeys = $visibleSegmentNodes->pluck('key');
        $visibleSegmentEdges = $segmentEdges
            ->filter(fn (array $edge): bool => $this->isVisible($edge)
                && $visibleNodeKeys->contains($edge['from_node_key'] ?? null)
                && $visibleNodeKeys->contains($edge['to_node_key'] ?? null))
            ->values();
        $reachableNodeKeys = $this->reachableNodeKeys(
            startNodeKey: is_string($mechanismNodeKey) ? $mechanismNodeKey : '',
            edges: $visibleSegmentEdges->all(),
        );
        $outcomeEdges = $visibleSegmentEdges
            ->filter(function (array $edge) use ($nodesByKey): bool {
                if (in_array($edge['role'] ?? null, ['consequence', 'exits', 'exits_to'], true)) {
                    return true;
                }

                if (($edge['role'] ?? null) !== 'branch') {
                    return false;
                }

                $target = $nodesByKey->get($edge['to_node_key'] ?? null);

                return is_array($target)
                    && in_array($target['role'] ?? null, ['consequence', 'exit'], true);
            })
            ->values();
        $outcomeTargetKeys = $outcomeEdges
            ->pluck('to_node_key')
            ->filter(fn (mixed $key): bool => is_string($key))
            ->unique();
        $outcomesByTrigger = $outcomeEdges
            ->groupBy('from_node_key')
            ->map(fn ($triggerEdges): array => collect($triggerEdges)
                ->map(function (array $edge) use ($nodesByKey, $highwayKey): ?array {
                    $node = $nodesByKey->get($edge['to_node_key'] ?? null);

                    return is_array($node) ? [
                        'edge' => $edge,
                        'node' => $node,
                        'exit_anchor' => $this->exitLinks->anchor(
                            highwayKey: $highwayKey,
                            edgeKey: (string) ($edge['key'] ?? ''),
                        ),
                        'fact_target' => $this->exitLinks->factTarget($node),
                    ] : null;
                })
                ->filter()
                ->values()
                ->all());
        $journeyNodes = $visibleSegmentNodes
            ->filter(fn (array $node): bool => in_array($node['key'], $reachableNodeKeys, true))
            ->reject(fn (array $node): bool => $node['key'] === $mechanismNodeKey)
            ->reject(fn (array $node): bool => $entryKeys->contains($node['key']))
            ->reject(fn (array $node): bool => $exitKeys->contains($node['key']))
            ->reject(fn (array $node): bool => $outcomeTargetKeys->contains($node['key']))
            ->reject(fn (array $node): bool => ($node['role'] ?? null) === 'trigger')
            ->map(function (array $node) use ($outcomesByTrigger): array {
                $node['outcomes'] = $outcomesByTrigger->get($node['key'], []);

                return $node;
            })
            ->values();
        $visibleTriggerKeys = $journeyNodes
            ->pluck('key')
            ->when(
                is_string($mechanismNodeKey),
                fn ($keys) => $keys->push($mechanismNodeKey),
            )
            ->filter(fn (mixed $key): bool => is_string($key))
            ->unique();

        return [
            ...$this->decorateAuthorityTarget($segment),
            'mechanism_node' => is_string($mechanismNodeKey)
                ? $nodesByKey->get($mechanismNodeKey)
                : null,
            'entry_nodes' => $segmentNodes
                ->filter(fn (array $node): bool => $entryKeys->contains($node['key']) && $this->isVisible($node))
                ->values()
                ->all(),
            'journey_nodes' => $journeyNodes->all(),
            'exit_nodes' => $visibleSegmentNodes
                ->filter(fn (array $node): bool => $exitKeys->contains($node['key']))
                ->values()
                ->all(),
            'edges' => $visibleSegmentEdges->all(),
            'mechanism_outcomes' => is_string($mechanismNodeKey)
                ? $outcomesByTrigger->get($mechanismNodeKey, [])
                : [],
            'additional_outcome_groups' => $outcomesByTrigger
                ->reject(fn (array $outcomes, string $triggerKey): bool => $visibleTriggerKeys->contains($triggerKey))
                ->map(function (array $outcomes, string $triggerKey) use ($nodesByKey): array {
                    return [
                        'trigger_node' => $nodesByKey->get($triggerKey),
                        'outcomes' => $outcomes,
                    ];
                })
                ->filter(fn (array $group): bool => is_array($group['trigger_node']))
                ->values()
                ->all(),
            'branch_edges' => $visibleSegmentEdges
                ->filter(fn (array $edge): bool => ($edge['role'] ?? null) === 'branch'
                    && ($edge['attributes']['operator'] ?? null) !== 'or')
                ->values()
                ->all(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $edges
     * @return array<int, string>
     */
    private function reachableNodeKeys(string $startNodeKey, array $edges): array
    {
        if ($startNodeKey === '') {
            return [];
        }

        $adjacency = [];

        foreach ($edges as $edge) {
            $from = $edge['from_node_key'] ?? null;
            $to = $edge['to_node_key'] ?? null;

            if (is_string($from) && is_string($to)) {
                $adjacency[$from][] = $to;
            }
        }

        $queue = [$startNodeKey];
        $visited = [$startNodeKey => true];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (! is_string($current)) {
                continue;
            }

            foreach ($adjacency[$current] ?? [] as $next) {
                if (isset($visited[$next])) {
                    continue;
                }

                $visited[$next] = true;
                $queue[] = $next;
            }
        }

        return array_keys($visited);
    }

    /**
     * @param array<int, array<string, mixed>> $entryRequirements
     * @param array<int, array<string, mixed>> $entryNodes
     * @param array<int, array<string, mixed>> $segments
     */
    private function highwayName(
        array $entryRequirements,
        array $entryNodes,
        array $segments,
    ): string {
        $labels = collect($entryRequirements)
            ->map(fn (array $requirement): string => collect($requirement['values'] ?? [])
                ->pluck('label')
                ->filter(fn (mixed $label): bool => is_string($label) && trim($label) !== '')
                ->implode(' or '))
            ->filter();

        if ($labels->isEmpty()) {
            $labels = collect($entryNodes)
                ->pluck('label')
                ->filter(fn (mixed $label): bool => is_string($label) && trim($label) !== '')
                ->map(fn (string $label): string => preg_replace(
                    '/^(Status|Tag|Source|Subsource|Webinar outcome):\s*/i',
                    '',
                    $label,
                ) ?? $label);
        }

        $labels = $labels
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

    /** @param mixed $element */
    private function isVisible(mixed $element): bool
    {
        return is_array($element)
            && ($element['attributes']['highway_visibility'] ?? null) !== 'hidden';
    }

    /**
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $segments
     * @param \Illuminate\Support\Collection<string, array<string, mixed>> $nodesByKey
     * @return array<int, array{
     *     criterion_key: string,
     *     criterion_label: string,
     *     operator: string,
     *     values: array<int, array{value: string, label: string, node_key: string}>
     * }>
     */
    private function entryRequirements($segments, $nodesByKey): array
    {
        $requirements = [];

        foreach ($segments as $segment) {
            $entryKeys = collect($segment['entry_node_keys'] ?? [])
                ->filter(fn (mixed $key): bool => is_string($key))
                ->values();
            $conditions = $segment['attributes']['eligibility_conditions'] ?? [];

            if (! is_array($conditions) || $conditions === []) {
                $conditions = $entryKeys
                    ->map(function (string $entryKey) use ($nodesByKey): ?array {
                        $node = $nodesByKey->get($entryKey);
                        $criterionKey = $node['attributes']['criterion_key'] ?? null;
                        $value = $node['attributes']['value'] ?? null;

                        if (! is_string($criterionKey) || ! is_string($value)) {
                            return null;
                        }

                        return [
                            'key' => $criterionKey,
                            'label' => $this->criterionLabel($criterionKey),
                            'values' => [$value],
                            'value_labels' => [
                                $node['attributes']['value_label']
                                    ?? $this->qualifierValueLabel($node, $criterionKey, $value),
                            ],
                        ];
                    })
                    ->filter()
                    ->groupBy('key')
                    ->map(function ($group): array {
                        $first = $group->first();

                        return [
                            'key' => $first['key'],
                            'label' => $first['label'],
                            'values' => $group->flatMap(fn (array $condition): array => $condition['values'])->all(),
                            'value_labels' => $group->flatMap(fn (array $condition): array => $condition['value_labels'])->all(),
                        ];
                    })
                    ->values()
                    ->all();
            }

            foreach ($conditions as $condition) {
                $criterionKey = $condition['key'] ?? null;
                $values = $condition['values'] ?? null;

                if (! is_string($criterionKey) || ! is_array($values)) {
                    continue;
                }

                $requirements[$criterionKey] ??= [
                    'criterion_key' => $criterionKey,
                    'criterion_label' => is_string($condition['label'] ?? null)
                        ? $condition['label']
                        : $this->criterionLabel($criterionKey),
                    'operator' => 'any',
                    'values' => [],
                ];

                foreach (array_values($values) as $valueIndex => $value) {
                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    $node = $entryKeys
                        ->map(fn (string $entryKey): ?array => $nodesByKey->get($entryKey))
                        ->first(fn (mixed $candidate): bool => is_array($candidate)
                            && ($candidate['attributes']['criterion_key'] ?? null) === $criterionKey
                            && ($candidate['attributes']['value'] ?? null) === $value);

                    if (! is_array($node)) {
                        continue;
                    }

                    $requirements[$criterionKey]['values'][$value] = [
                        'value' => $value,
                        'label' => $condition['value_labels'][$valueIndex]
                            ?? $node['attributes']['value_label']
                            ?? $this->qualifierValueLabel($node, $criterionKey, $value),
                        'node_key' => $node['key'],
                    ];
                }
            }
        }

        $priorities = [
            'status' => 10,
            'tag' => 20,
            'relationship' => 30,
            'webinar_outcome' => 40,
            'source' => 50,
            'subsource' => 60,
        ];
        $requirements = array_values(array_filter(
            $requirements,
            fn (array $requirement): bool => $requirement['values'] !== [],
        ));

        foreach ($requirements as &$requirement) {
            $requirement['values'] = array_values($requirement['values']);
            usort($requirement['values'], static fn (array $left, array $right): int => [
                $left['label'],
                $left['value'],
            ] <=> [
                $right['label'],
                $right['value'],
            ]);
        }
        unset($requirement);

        usort($requirements, static fn (array $left, array $right): int => [
            $priorities[$left['criterion_key']] ?? 100,
            $left['criterion_label'],
        ] <=> [
            $priorities[$right['criterion_key']] ?? 100,
            $right['criterion_label'],
        ]);

        return $requirements;
    }

    /**
     * @param array<int, array<string, mixed>> $requirements
     * @return array<string, array<int, string>>
     */
    private function requirementsQualifiers(array $requirements): array
    {
        $qualifiers = [];

        foreach ($requirements as $requirement) {
            $qualifiers[$requirement['criterion_key']] = array_values(array_unique(array_filter(
                array_column($requirement['values'], 'value'),
                fn (mixed $value): bool => is_string($value) && $value !== '',
            )));
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
     * @param array<int, array<string, mixed>> $nodes
     * @param array<int, array<string, mixed>> $edges
     * @return array<int, array<string, mixed>>
     */
    private function qualifierFilters(
        array $highways,
        array $nodes,
        array $edges,
    ): array {
        $filters = [];
        $priorities = [
            'status' => 10,
            'tag' => 20,
            'relationship' => 30,
            'webinar_outcome' => 40,
            'source' => 50,
            'subsource' => 60,
        ];
        $entryHighwayKeysByNode = [];
        $highwayKeysBySegment = [];

        foreach ($highways as $highway) {
            $highwayKey = $highway['key'] ?? null;

            if (! is_string($highwayKey) || $highwayKey === '') {
                continue;
            }

            foreach ($highway['entry_node_keys'] ?? [] as $nodeKey) {
                if (is_string($nodeKey) && $nodeKey !== '') {
                    $entryHighwayKeysByNode[$nodeKey][$highwayKey] = true;
                }
            }

            foreach ($highway['segment_keys'] ?? [] as $segmentKey) {
                if (is_string($segmentKey) && $segmentKey !== '') {
                    $highwayKeysBySegment[$segmentKey][$highwayKey] = true;
                }
            }
        }

        $producerHighwayKeysByNode = [];

        foreach ($edges as $edge) {
            if (! is_array($edge)
                || ($edge['role'] ?? null) !== 'consequence'
                || ! $this->isVisible($edge)
            ) {
                continue;
            }

            $nodeKey = $edge['to_node_key'] ?? null;
            $segmentKey = $edge['segment_key'] ?? null;

            if (! is_string($nodeKey) || $nodeKey === ''
                || ! is_string($segmentKey) || $segmentKey === ''
            ) {
                continue;
            }

            foreach (array_keys($highwayKeysBySegment[$segmentKey] ?? []) as $highwayKey) {
                $producerHighwayKeysByNode[$nodeKey][$highwayKey] = true;
            }
        }

        foreach ($nodes as $node) {
            if (! is_array($node) || ! $this->isVisible($node)) {
                continue;
            }

            $nodeKey = $node['key'] ?? null;
            $criterionKey = $node['attributes']['criterion_key'] ?? null;
            $value = $node['attributes']['value'] ?? null;

            if (! is_string($nodeKey) || $nodeKey === ''
                || ! is_string($criterionKey) || $criterionKey === ''
                || ! is_string($value) || $value === ''
                || ($criterionKey === 'tag' && ($node['attributes']['present'] ?? true) !== true)
            ) {
                continue;
            }

            $entryHighwayKeys = array_keys($entryHighwayKeysByNode[$nodeKey] ?? []);
            $producerHighwayKeys = array_keys($producerHighwayKeysByNode[$nodeKey] ?? []);

            if ($entryHighwayKeys === [] && $producerHighwayKeys === []) {
                continue;
            }

            sort($entryHighwayKeys);
            sort($producerHighwayKeys);

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
                'node_key' => $nodeKey,
                'highway_keys' => [],
                'entry_highway_keys' => [],
                'producer_highway_keys' => [],
            ];

            $option = &$filters[$criterionKey]['options'][$value];
            $option['highway_keys'] = array_values(array_unique([
                ...$option['highway_keys'],
                ...$entryHighwayKeys,
            ]));
            $option['entry_highway_keys'] = array_values(array_unique([
                ...$option['entry_highway_keys'],
                ...$entryHighwayKeys,
            ]));
            $option['producer_highway_keys'] = array_values(array_unique([
                ...$option['producer_highway_keys'],
                ...$producerHighwayKeys,
            ]));
            sort($option['highway_keys']);
            sort($option['entry_highway_keys']);
            sort($option['producer_highway_keys']);
            unset($option);
        }

        foreach ($filters as &$filter) {
            $options = array_values($filter['options']);

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
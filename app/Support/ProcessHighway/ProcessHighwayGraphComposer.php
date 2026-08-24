<?php

namespace App\Support\ProcessHighway;

use App\Support\ProcessHighway\Contracts\ProcessHighwayContributor;
use App\Support\ProcessHighway\Data\ProcessHighwayAuthority;
use App\Support\ProcessHighway\Data\ProcessHighwayContribution;
use App\Support\ProcessHighway\Data\ProcessHighwayEdge;
use App\Support\ProcessHighway\Data\ProcessHighwayEditTarget;
use App\Support\ProcessHighway\Data\ProcessHighwayLane;
use App\Support\ProcessHighway\Data\ProcessHighwayNode;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ProcessHighwayGraphComposer
{
    public function __construct(
        private readonly ProcessHighwayMapBuilder $mapBuilder,
    ) {}

    /**
     * @param iterable<ProcessHighwayContributor> $contributors
     * @return array<string, mixed>
     */
    public function compose(iterable $contributors): array
    {
        $contributions = [];

        foreach ($contributors as $contributor) {
            if (! $contributor instanceof ProcessHighwayContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Process Highway contributor [%s] must implement [%s].',
                    get_debug_type($contributor),
                    ProcessHighwayContributor::class,
                ));
            }

            foreach ($contributor->contributions() as $contribution) {
                if (! $contribution instanceof ProcessHighwayContribution) {
                    throw new InvalidArgumentException(sprintf(
                        'Process Highway contributor [%s] must return only [%s] instances.',
                        $contributor::class,
                        ProcessHighwayContribution::class,
                    ));
                }

                $this->validateContribution($contribution, $contributor::class);
                $contributions[] = $contribution;
            }
        }

        usort($contributions, static fn (
            ProcessHighwayContribution $left,
            ProcessHighwayContribution $right,
        ): int => [
            $left->subjectKey,
            $left->lane->sortOrder,
            $left->lane->key,
            $left->sortOrder,
            $left->name,
            $left->key,
        ] <=> [
            $right->subjectKey,
            $right->lane->sortOrder,
            $right->lane->key,
            $right->sortOrder,
            $right->name,
            $right->key,
        ]);

        $segments = [];
        $segmentKeys = [];
        $nodes = [];
        $nodeMembership = [];
        $edges = [];
        $edgeMembership = [];
        $lanes = [];

        foreach ($contributions as $contribution) {
            if (isset($segmentKeys[$contribution->key])) {
                throw new InvalidArgumentException(
                    "Duplicate Process Highway segment key [{$contribution->key}].",
                );
            }

            $segmentKeys[$contribution->key] = true;
            $segments[] = $contribution->toArray();
            $this->mergeLane($lanes, $contribution->lane, $contribution->key);

            foreach ($contribution->nodes as $node) {
                $nodes[$node->key] = isset($nodes[$node->key])
                    ? $this->mergeNode($nodes[$node->key], $node)
                    : $node;
                $nodeMembership[$node->key][] = $contribution->key;
            }

            foreach ($contribution->edges as $edge) {
                if (isset($edges[$edge->key])) {
                    throw new InvalidArgumentException(
                        "Duplicate Process Highway edge key [{$edge->key}].",
                    );
                }

                $edges[$edge->key] = $edge;
                $edgeMembership[$edge->key] = $contribution->key;
            }
        }

        foreach ($edges as $edge) {
            foreach ([$edge->fromNodeKey, $edge->toNodeKey] as $nodeKey) {
                if (! isset($nodes[$nodeKey])) {
                    throw new InvalidArgumentException(sprintf(
                        'Process Highway edge [%s] references missing node [%s].',
                        $edge->key,
                        $nodeKey,
                    ));
                }
            }
        }

        $serializedNodes = collect($nodes)
            ->sortBy(fn (ProcessHighwayNode $node): array => [
                $node->sortOrder,
                $node->label,
                $node->key,
            ])
            ->map(function (ProcessHighwayNode $node) use ($nodeMembership): array {
                return [
                    ...$node->toArray(),
                    'segment_keys' => array_values(array_unique(
                        $nodeMembership[$node->key] ?? [],
                    )),
                ];
            })
            ->values()
            ->all();

        $serializedEdges = collect($edges)
            ->sortBy(fn (ProcessHighwayEdge $edge): array => [
                $edge->sortOrder,
                $edge->key,
            ])
            ->map(function (ProcessHighwayEdge $edge) use ($edgeMembership): array {
                return [
                    ...$edge->toArray(),
                    'segment_key' => $edgeMembership[$edge->key],
                ];
            })
            ->values()
            ->all();

        $subjects = $this->subjects($lanes);
        $map = $this->mapBuilder->build(
            segments: $segments,
            nodes: $serializedNodes,
            edges: $serializedEdges,
            subjects: $subjects,
        );

        return [
            'schema_version' => 2,
            'subject_count' => count($subjects),
            'lane_count' => count($lanes),
            'highway_count' => $map['highway_count'],
            'segment_count' => count($segments),
            'node_count' => count($serializedNodes),
            'edge_count' => count($serializedEdges),
            'source_count' => collect($segments)
                ->pluck('source_key')
                ->filter()
                ->unique()
                ->count(),
            'subjects' => $map['subjects'],
            'highways' => $map['highways'],
            'qualifier_filters' => $map['qualifier_filters'],
            'segments' => $segments,
            'nodes' => $serializedNodes,
            'edges' => $serializedEdges,
        ];
    }

    private function validateContribution(
        ProcessHighwayContribution $contribution,
        string $contributorClass,
    ): void {
        foreach ([
            'source key' => $contribution->sourceKey,
            'segment key' => $contribution->key,
            'name' => $contribution->name,
            'subject key' => $contribution->subjectKey,
            'mechanism node key' => $contribution->mechanismNodeKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(
                    "Process Highway contributor [{$contributorClass}] returned an empty {$field}.",
                );
            }
        }

        if ($contribution->lane->subjectKey !== $contribution->subjectKey) {
            throw new InvalidArgumentException(sprintf(
                'Process Highway segment [%s] lane subject [%s] does not match segment subject [%s].',
                $contribution->key,
                $contribution->lane->subjectKey,
                $contribution->subjectKey,
            ));
        }

        if (! in_array($contribution->lane->scope, [
            ProcessHighwayLane::SCOPE_STANDARD,
            ProcessHighwayLane::SCOPE_RELATIONSHIP,
        ], true)) {
            throw new InvalidArgumentException(
                "Process Highway segment [{$contribution->key}] has invalid lane scope [{$contribution->lane->scope}].",
            );
        }

        if (
            $contribution->lane->scope === ProcessHighwayLane::SCOPE_STANDARD
            && $contribution->lane->relationshipKey !== null
        ) {
            throw new InvalidArgumentException(
                "Process Highway standard lane [{$contribution->lane->key}] cannot declare a relationship key.",
            );
        }

        $this->validateAuthority($contribution->authority, "segment [{$contribution->key}]");

        if (! $this->hasNavigationTarget($contribution->authority)) {
            throw new InvalidArgumentException(
                "Process Highway segment [{$contribution->key}] must declare an authoritative GET navigation target.",
            );
        }

        $nodeKeys = [];

        foreach ($contribution->nodes as $node) {
            if (! $node instanceof ProcessHighwayNode) {
                throw new InvalidArgumentException(
                    "Process Highway segment [{$contribution->key}] contains a non-node value.",
                );
            }

            if (trim($node->key) === '' || trim($node->label) === '') {
                throw new InvalidArgumentException(
                    "Process Highway segment [{$contribution->key}] contains a node with an empty key or label.",
                );
            }

            if (! in_array($node->role, ProcessHighwayNode::ROLES, true)) {
                throw new InvalidArgumentException(
                    "Process Highway node [{$node->key}] has invalid role [{$node->role}].",
                );
            }

            if (isset($nodeKeys[$node->key])) {
                throw new InvalidArgumentException(
                    "Process Highway segment [{$contribution->key}] contains duplicate node [{$node->key}].",
                );
            }

            $nodeKeys[$node->key] = true;
            $this->validateAuthority($node->authority, "node [{$node->key}]");
        }

        if (! isset($nodeKeys[$contribution->mechanismNodeKey])) {
            throw new InvalidArgumentException(
                "Process Highway segment [{$contribution->key}] is missing mechanism node [{$contribution->mechanismNodeKey}].",
            );
        }

        foreach ([
            'entry' => $contribution->entryNodeKeys,
            'exit' => $contribution->exitNodeKeys,
        ] as $role => $keys) {
            if ($keys === []) {
                throw new InvalidArgumentException(
                    "Process Highway segment [{$contribution->key}] must declare at least one {$role} node.",
                );
            }

            foreach ($keys as $key) {
                if (! isset($nodeKeys[$key])) {
                    throw new InvalidArgumentException(
                        "Process Highway segment [{$contribution->key}] references missing {$role} node [{$key}].",
                    );
                }
            }
        }

        $edgeKeys = [];

        foreach ($contribution->edges as $edge) {
            if (! $edge instanceof ProcessHighwayEdge) {
                throw new InvalidArgumentException(
                    "Process Highway segment [{$contribution->key}] contains a non-edge value.",
                );
            }

            if (! in_array($edge->role, ProcessHighwayEdge::ROLES, true)) {
                throw new InvalidArgumentException(
                    "Process Highway edge [{$edge->key}] has invalid role [{$edge->role}].",
                );
            }

            if (isset($edgeKeys[$edge->key])) {
                throw new InvalidArgumentException(
                    "Process Highway segment [{$contribution->key}] contains duplicate edge [{$edge->key}].",
                );
            }

            foreach ([$edge->fromNodeKey, $edge->toNodeKey] as $nodeKey) {
                if (! isset($nodeKeys[$nodeKey])) {
                    throw new InvalidArgumentException(sprintf(
                        'Process Highway segment [%s] edge [%s] must include endpoint node [%s] in its fragment.',
                        $contribution->key,
                        $edge->key,
                        $nodeKey,
                    ));
                }
            }

            $edgeKeys[$edge->key] = true;
            $this->validateAuthority($edge->authority, "edge [{$edge->key}]");
        }
    }

    private function validateAuthority(ProcessHighwayAuthority $authority, string $context): void
    {
        if (trim($authority->ownerKey) === '') {
            throw new InvalidArgumentException(
                "Process Highway {$context} must declare an owner module.",
            );
        }

        if (! is_array(config("modules.modules.{$authority->ownerKey}"))) {
            throw new InvalidArgumentException(sprintf(
                'Process Highway %s owner module [%s] is not registered.',
                $context,
                $authority->ownerKey,
            ));
        }

        if ($authority->editTargets === []) {
            throw new InvalidArgumentException(
                "Process Highway {$context} must declare at least one authoritative edit target.",
            );
        }

        foreach ($authority->editTargets as $target) {
            if (! $target instanceof ProcessHighwayEditTarget) {
                throw new InvalidArgumentException(
                    "Process Highway {$context} contains an invalid edit target.",
                );
            }

            if (! in_array($target->mode, [
                ProcessHighwayEditTarget::MODE_LINK,
                ProcessHighwayEditTarget::MODE_INLINE,
            ], true)) {
                throw new InvalidArgumentException(
                    "Process Highway {$context} edit target has invalid mode [{$target->mode}].",
                );
            }

            if (
                trim($target->ownerKey) === ''
                || trim($target->label) === ''
                || trim($target->url) === ''
                || trim($target->resourceType) === ''
                || trim($target->resourceKey) === ''
                || trim($target->method) === ''
            ) {
                throw new InvalidArgumentException(
                    "Process Highway {$context} edit target is incomplete.",
                );
            }

            if (! is_array(config("modules.modules.{$target->ownerKey}"))) {
                throw new InvalidArgumentException(sprintf(
                    'Process Highway %s edit target owner module [%s] is not registered.',
                    $context,
                    $target->ownerKey,
                ));
            }

            if (
                $target->mode === ProcessHighwayEditTarget::MODE_INLINE
                && ($target->capability === null || trim($target->capability) === '')
            ) {
                throw new InvalidArgumentException(
                    "Process Highway {$context} inline edit target must declare a capability.",
                );
            }
        }
    }

    private function hasNavigationTarget(ProcessHighwayAuthority $authority): bool
    {
        foreach ($authority->editTargets as $target) {
            if (
                $target instanceof ProcessHighwayEditTarget
                && $target->mode === ProcessHighwayEditTarget::MODE_LINK
                && $target->method === 'GET'
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string, mixed>> $lanes
     */
    private function mergeLane(
        array &$lanes,
        ProcessHighwayLane $lane,
        string $segmentKey,
    ): void {
        if (! isset($lanes[$lane->key])) {
            $lanes[$lane->key] = [
                ...$lane->toArray(),
                'segment_keys' => [],
            ];
        } elseif (
            $lanes[$lane->key]['subject_key'] !== $lane->subjectKey
            || $lanes[$lane->key]['scope'] !== $lane->scope
            || $lanes[$lane->key]['relationship_key'] !== $lane->relationshipKey
        ) {
            throw new InvalidArgumentException(
                "Process Highway lane [{$lane->key}] was contributed with conflicting semantics.",
            );
        }

        $lanes[$lane->key]['segment_keys'][] = $segmentKey;
    }

    private function mergeNode(
        ProcessHighwayNode $existing,
        ProcessHighwayNode $incoming,
    ): ProcessHighwayNode {
        if ($existing->authority->ownerKey !== $incoming->authority->ownerKey) {
            throw new InvalidArgumentException(sprintf(
                'Process Highway semantic node [%s] has conflicting owners [%s] and [%s].',
                $existing->key,
                $existing->authority->ownerKey,
                $incoming->authority->ownerKey,
            ));
        }

        if ($existing->role !== $incoming->role) {
            throw new InvalidArgumentException(sprintf(
                'Process Highway semantic node [%s] has conflicting roles [%s] and [%s].',
                $existing->key,
                $existing->role,
                $incoming->role,
            ));
        }

        if (! $existing->referenceOnly && ! $incoming->referenceOnly) {
            throw new InvalidArgumentException(
                "Process Highway semantic node [{$existing->key}] has more than one authoritative definition.",
            );
        }

        $base = $existing->referenceOnly && ! $incoming->referenceOnly
            ? $incoming
            : $existing;

        return new ProcessHighwayNode(
            key: $base->key,
            label: $base->label,
            role: $base->role,
            authority: $existing->authority->merge($incoming->authority),
            description: $base->description,
            detail: $base->detail,
            state: $base->state,
            stateLabel: $base->stateLabel,
            sortOrder: min($existing->sortOrder, $incoming->sortOrder),
            referenceOnly: $existing->referenceOnly && $incoming->referenceOnly,
            attributes: array_replace_recursive(
                $existing->attributes,
                $incoming->attributes,
            ),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $lanes
     * @return array<int, array<string, mixed>>
     */
    private function subjects(array $lanes): array
    {
        return collect($lanes)
            ->sortBy(fn (array $lane): array => [
                $lane['subject_key'],
                $lane['sort_order'],
                $lane['label'],
            ])
            ->groupBy('subject_key')
            ->map(function (Collection $subjectLanes, string $subjectKey): array {
                return [
                    'key' => $subjectKey,
                    'label' => $subjectKey === 'contacts'
                        ? (string) config('contacts.labels.plural', 'Contacts')
                        : Str::headline($subjectKey),
                    'lanes' => $subjectLanes->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

}
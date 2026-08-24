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

        $processes = [];
        $processKeys = [];
        $nodes = [];
        $nodeMembership = [];
        $edges = [];
        $edgeMembership = [];
        $lanes = [];

        foreach ($contributions as $contribution) {
            if (isset($processKeys[$contribution->key])) {
                throw new InvalidArgumentException(
                    "Duplicate Process Highway process key [{$contribution->key}].",
                );
            }

            $processKeys[$contribution->key] = true;
            $processes[] = $contribution->toArray();
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
                    'process_keys' => array_values(array_unique(
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
                    'process_key' => $edgeMembership[$edge->key],
                ];
            })
            ->values()
            ->all();

        $subjects = $this->subjects($lanes);
        $legacyGroups = $this->legacyGroups($contributions);

        return [
            'schema_version' => 1,
            'subject_count' => count($subjects),
            'lane_count' => count($lanes),
            'process_count' => count($processes),
            'node_count' => count($serializedNodes),
            'edge_count' => count($serializedEdges),
            'source_count' => collect($processes)
                ->pluck('source_key')
                ->filter()
                ->unique()
                ->count(),
            'subjects' => $subjects,
            'processes' => $processes,
            'nodes' => $serializedNodes,
            'edges' => $serializedEdges,
            'groups' => $legacyGroups,
        ];
    }

    private function validateContribution(
        ProcessHighwayContribution $contribution,
        string $contributorClass,
    ): void {
        foreach ([
            'source key' => $contribution->sourceKey,
            'process key' => $contribution->key,
            'name' => $contribution->name,
            'subject key' => $contribution->subjectKey,
            'process node key' => $contribution->processNodeKey,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException(
                    "Process Highway contributor [{$contributorClass}] returned an empty {$field}.",
                );
            }
        }

        if ($contribution->lane->subjectKey !== $contribution->subjectKey) {
            throw new InvalidArgumentException(sprintf(
                'Process Highway process [%s] lane subject [%s] does not match process subject [%s].',
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
                "Process Highway process [{$contribution->key}] has invalid lane scope [{$contribution->lane->scope}].",
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

        $this->validateAuthority($contribution->authority, "process [{$contribution->key}]");

        $nodeKeys = [];

        foreach ($contribution->nodes as $node) {
            if (! $node instanceof ProcessHighwayNode) {
                throw new InvalidArgumentException(
                    "Process Highway process [{$contribution->key}] contains a non-node value.",
                );
            }

            if (trim($node->key) === '' || trim($node->label) === '') {
                throw new InvalidArgumentException(
                    "Process Highway process [{$contribution->key}] contains a node with an empty key or label.",
                );
            }

            if (! in_array($node->role, ProcessHighwayNode::ROLES, true)) {
                throw new InvalidArgumentException(
                    "Process Highway node [{$node->key}] has invalid role [{$node->role}].",
                );
            }

            if (isset($nodeKeys[$node->key])) {
                throw new InvalidArgumentException(
                    "Process Highway process [{$contribution->key}] contains duplicate node [{$node->key}].",
                );
            }

            $nodeKeys[$node->key] = true;
            $this->validateAuthority($node->authority, "node [{$node->key}]");
        }

        if (! isset($nodeKeys[$contribution->processNodeKey])) {
            throw new InvalidArgumentException(
                "Process Highway process [{$contribution->key}] is missing process node [{$contribution->processNodeKey}].",
            );
        }

        foreach ([
            'entry' => $contribution->entryNodeKeys,
            'exit' => $contribution->exitNodeKeys,
        ] as $role => $keys) {
            if ($keys === []) {
                throw new InvalidArgumentException(
                    "Process Highway process [{$contribution->key}] must declare at least one {$role} node.",
                );
            }

            foreach ($keys as $key) {
                if (! isset($nodeKeys[$key])) {
                    throw new InvalidArgumentException(
                        "Process Highway process [{$contribution->key}] references missing {$role} node [{$key}].",
                    );
                }
            }
        }

        $edgeKeys = [];

        foreach ($contribution->edges as $edge) {
            if (! $edge instanceof ProcessHighwayEdge) {
                throw new InvalidArgumentException(
                    "Process Highway process [{$contribution->key}] contains a non-edge value.",
                );
            }

            if (! in_array($edge->role, ProcessHighwayEdge::ROLES, true)) {
                throw new InvalidArgumentException(
                    "Process Highway edge [{$edge->key}] has invalid role [{$edge->role}].",
                );
            }

            if (isset($edgeKeys[$edge->key])) {
                throw new InvalidArgumentException(
                    "Process Highway process [{$contribution->key}] contains duplicate edge [{$edge->key}].",
                );
            }

            foreach ([$edge->fromNodeKey, $edge->toNodeKey] as $nodeKey) {
                if (! isset($nodeKeys[$nodeKey])) {
                    throw new InvalidArgumentException(sprintf(
                        'Process Highway process [%s] edge [%s] must include endpoint node [%s] in its fragment.',
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

    /**
     * @param array<string, array<string, mixed>> $lanes
     */
    private function mergeLane(
        array &$lanes,
        ProcessHighwayLane $lane,
        string $processKey,
    ): void {
        if (! isset($lanes[$lane->key])) {
            $lanes[$lane->key] = [
                ...$lane->toArray(),
                'process_keys' => [],
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

        $lanes[$lane->key]['process_keys'][] = $processKey;
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

    /**
     * Compatibility projection for the existing Batch 4 Blade surface.
     * Batch 6B will render the graph fields directly and remove this adapter.
     *
     * @param array<int, ProcessHighwayContribution> $contributions
     * @return Collection<int, array<string, mixed>>
     */
    private function legacyGroups(array $contributions): Collection
    {
        return collect($contributions)
            ->groupBy(fn (ProcessHighwayContribution $process): string => $process->lane->key)
            ->map(function (Collection $items, string $laneKey): array {
                /** @var ProcessHighwayContribution $first */
                $first = $items->first();

                return [
                    'key' => $laneKey,
                    'label' => $first->lane->label,
                    'priority' => $first->lane->sortOrder,
                    'processes' => $items
                        ->map(fn (ProcessHighwayContribution $process): array => $this->legacyProcess($process))
                        ->values(),
                ];
            })
            ->sortBy('priority')
            ->values();
    }

    /** @return array<string, mixed> */
    private function legacyProcess(ProcessHighwayContribution $process): array
    {
        $nodes = collect($process->nodes)->keyBy('key');
        $entryLabels = collect($process->entryNodeKeys)
            ->map(fn (string $key): ?string => $nodes->get($key)?->label)
            ->filter()
            ->values();
        $steps = collect($process->nodes)
            ->filter(fn (ProcessHighwayNode $node): bool => $node->key !== $process->processNodeKey)
            ->filter(fn (ProcessHighwayNode $node): bool => in_array($node->role, [
                ProcessHighwayNode::ROLE_GATEWAY,
                ProcessHighwayNode::ROLE_ACTION,
                ProcessHighwayNode::ROLE_CONSEQUENCE,
            ], true))
            ->map(fn (ProcessHighwayNode $node): array => [
                'name' => $node->label,
                'detail' => $node->detail,
            ])
            ->values()
            ->all();
        $outcomes = collect($process->exitNodeKeys)
            ->map(fn (string $key): ?string => $nodes->get($key)?->label)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $authority = $process->authority->toArray();
        $primaryEdit = $authority['edit_targets'][0] ?? null;

        return [
            'source_key' => $process->sourceKey,
            'source_label' => $authority['owner_label'],
            'key' => $process->key,
            'name' => $process->name,
            'description' => $process->description,
            'category' => $process->lane->key,
            'category_label' => $process->lane->label,
            'category_priority' => $process->lane->sortOrder,
            'sort_order' => $process->sortOrder,
            'state' => $process->state,
            'state_label' => $process->stateLabel,
            'starts_when' => $process->entrySummary
                ?? ($entryLabels->isNotEmpty()
                    ? $entryLabels->implode(' and ')
                    : 'The owning feature starts this process.'),
            'steps' => $steps,
            'outcomes' => $outcomes,
            'details' => $process->details,
            'attributes' => $process->attributes,
            'edit_url' => is_array($primaryEdit) ? $primaryEdit['url'] : null,
            'edit_label' => is_array($primaryEdit) ? $primaryEdit['label'] : 'Open',
        ];
    }
}
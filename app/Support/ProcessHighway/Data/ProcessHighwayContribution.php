<?php

namespace App\Support\ProcessHighway\Data;

final readonly class ProcessHighwayContribution
{
    /**
     * @param array<int, ProcessHighwayNode> $nodes
     * @param array<int, ProcessHighwayEdge> $edges
     * @param array<int, string> $entryNodeKeys
     * @param array<int, string> $exitNodeKeys
     * @param array<int, array{label: string, value: string}> $details
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public string $sourceKey,
        public string $key,
        public string $name,
        public string $description,
        public string $subjectKey,
        public ProcessHighwayLane $lane,
        public string $mechanismNodeKey,
        public ProcessHighwayAuthority $authority,
        public array $nodes,
        public array $edges,
        public array $entryNodeKeys,
        public array $exitNodeKeys,
        public string $state = 'configured',
        public string $stateLabel = 'Configured',
        public ?string $entrySummary = null,
        public int $sortOrder = 100,
        public array $details = [],
        public array $attributes = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'subject_key' => $this->subjectKey,
            'lane_key' => $this->lane->key,
            'mechanism_node_key' => $this->mechanismNodeKey,
            'node_keys' => array_values(array_map(
                fn (ProcessHighwayNode $node): string => $node->key,
                $this->nodes,
            )),
            'edge_keys' => array_values(array_map(
                fn (ProcessHighwayEdge $edge): string => $edge->key,
                $this->edges,
            )),
            'entry_node_keys' => array_values($this->entryNodeKeys),
            'exit_node_keys' => array_values($this->exitNodeKeys),
            'state' => $this->state,
            'state_label' => $this->stateLabel,
            'entry_summary' => $this->entrySummary,
            'sort_order' => $this->sortOrder,
            'details' => $this->details,
            'authority' => $this->authority->toArray(),
            'attributes' => $this->attributes,
        ];
    }
}
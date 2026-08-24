<?php

namespace App\Support\ProcessHighway\Data;

final readonly class ProcessHighwayEdge
{
    public const ROLE_REQUIRES = 'requires';

    public const ROLE_STARTS = 'starts';

    public const ROLE_CONTINUES = 'continues';

    public const ROLE_BRANCH = 'branch';

    public const ROLE_CONSEQUENCE = 'consequence';

    public const ROLE_EXITS = 'exits';

    public const ROLE_EXITS_TO = 'exits_to';

    public const ROLES = [
        self::ROLE_REQUIRES,
        self::ROLE_STARTS,
        self::ROLE_CONTINUES,
        self::ROLE_BRANCH,
        self::ROLE_CONSEQUENCE,
        self::ROLE_EXITS,
        self::ROLE_EXITS_TO,
    ];

    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $key,
        public string $fromNodeKey,
        public string $toNodeKey,
        public string $role,
        public ProcessHighwayAuthority $authority,
        public ?string $label = null,
        public int $sortOrder = 100,
        public array $attributes = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'from_node_key' => $this->fromNodeKey,
            'to_node_key' => $this->toNodeKey,
            'role' => $this->role,
            'label' => $this->label,
            'sort_order' => $this->sortOrder,
            'authority' => $this->authority->toArray(),
            'attributes' => $this->attributes,
        ];
    }
}
<?php

namespace App\Support\ProcessHighway\Data;

final readonly class ProcessHighwayNode
{
    public const ROLE_TRIGGER = 'trigger';

    public const ROLE_QUALIFIER = 'qualifier';

    public const ROLE_GATEWAY = 'gateway';

    public const ROLE_PROCESS = 'process';

    public const ROLE_ACTION = 'action';

    public const ROLE_CONSEQUENCE = 'consequence';

    public const ROLE_EXIT = 'exit';

    public const ROLES = [
        self::ROLE_TRIGGER,
        self::ROLE_QUALIFIER,
        self::ROLE_GATEWAY,
        self::ROLE_PROCESS,
        self::ROLE_ACTION,
        self::ROLE_CONSEQUENCE,
        self::ROLE_EXIT,
    ];

    /** @param array<string, mixed> $attributes */
    public function __construct(
        public string $key,
        public string $label,
        public string $role,
        public ProcessHighwayAuthority $authority,
        public ?string $description = null,
        public ?string $detail = null,
        public string $state = 'configured',
        public string $stateLabel = 'Configured',
        public int $sortOrder = 100,
        public bool $referenceOnly = false,
        public array $attributes = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'detail' => $this->detail,
            'role' => $this->role,
            'state' => $this->state,
            'state_label' => $this->stateLabel,
            'sort_order' => $this->sortOrder,
            'reference_only' => $this->referenceOnly,
            'authority' => $this->authority->toArray(),
            'attributes' => $this->attributes,
        ];
    }
}
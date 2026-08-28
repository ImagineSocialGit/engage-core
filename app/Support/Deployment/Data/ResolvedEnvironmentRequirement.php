<?php

namespace App\Support\Deployment\Data;

use App\Support\Environment\Data\EnvironmentVariableDefinition;

final readonly class ResolvedEnvironmentRequirement
{
    public const STATUS_READY = 'ready';
    public const STATUS_MISSING = 'missing';
    public const STATUS_UNRESOLVED = 'unresolved';
    public const STATUS_MISMATCH = 'mismatch';
    public const STATUS_DEFAULT = 'default';
    public const STATUS_OPTIONAL = 'optional';

    public function __construct(
        public EnvironmentVariableDefinition $definition,
        public EnvironmentRequirement $requirement,
        public string $owner,
        public string $status,
        public string $targetPath,
        public bool $persisted,
    ) {}

    public function blocksDeployment(): bool
    {
        return $this->requirement->isRequired()
            && in_array($this->status, [
                self::STATUS_MISSING,
                self::STATUS_UNRESOLVED,
                self::STATUS_MISMATCH,
            ], true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->definition->key,
            'scope' => $this->definition->scope,
            'owner' => $this->owner,
            'secret' => $this->definition->secret,
            'requirement' => $this->requirement->requirement,
            'reason' => $this->requirement->reason,
            'status' => $this->status,
            'target_path' => $this->targetPath,
            'persisted' => $this->persisted,
        ];
    }
}
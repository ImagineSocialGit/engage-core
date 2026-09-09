<?php

namespace App\Support\Deployment\Data;

final readonly class ResolvedDeploymentSetupStep
{
    public function __construct(
        public string $owner,
        public DeploymentSetupStep $step,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'owner' => $this->owner,
            ...$this->step->toArray(),
        ];
    }
}
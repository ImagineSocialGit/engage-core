<?php

namespace App\Support\Deployment\Data;

final readonly class DeploymentPlan
{
    /**
     * @param array<int, string> $enabledModules
     * @param array<int, ResolvedEnvironmentRequirement> $environmentRequirements
     * @param array<int, string> $unusedEnvironmentKeys
     * @param array<int, string> $coveredOwners
     */
    public function __construct(
        public string $environment,
        public string $clientKey,
        public array $enabledModules,
        public array $environmentRequirements,
        public array $unusedEnvironmentKeys,
        public array $coveredOwners,
    ) {}

    /** @return array<int, ResolvedEnvironmentRequirement> */
    public function blockingEnvironmentRequirements(): array
    {
        return array_values(array_filter(
            $this->environmentRequirements,
            static fn (ResolvedEnvironmentRequirement $requirement): bool => $requirement->blocksDeployment(),
        ));
    }

    public function ready(): bool
    {
        return $this->blockingEnvironmentRequirements() === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'client_key' => $this->clientKey,
            'enabled_modules' => $this->enabledModules,
            'covered_owners' => $this->coveredOwners,
            'ready' => $this->ready(),
            'environment_requirements' => array_map(
                static fn (ResolvedEnvironmentRequirement $requirement): array => $requirement->toArray(),
                $this->environmentRequirements,
            ),
            'unused_environment_keys' => $this->unusedEnvironmentKeys,
        ];
    }
}
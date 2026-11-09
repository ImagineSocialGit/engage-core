<?php

namespace App\Support\Deployment\Data;

final readonly class DeploymentPlan
{
    /**
     * @param array<int, string> $enabledModules
     * @param array<int, ResolvedEnvironmentRequirement> $environmentRequirements
     * @param array<int, string> $unusedEnvironmentKeys
     * @param array<int, string> $coveredOwners
     * @param array<int, ResolvedDeploymentSetupStep> $setupSteps
     * @param array<int, string> $knownModules
     * @param array<int, string> $selectedModules
     * @param array<int, string> $missingModuleDefinitions
     * @param array<int, EnvironmentIntegrityFinding> $environmentIntegrityFindings
     */
    public function __construct(
        public string $environment,
        public string $clientKey,
        public array $enabledModules,
        public array $environmentRequirements,
        public array $unusedEnvironmentKeys,
        public array $coveredOwners,
        public array $setupSteps = [],
        public array $knownModules = [],
        public array $selectedModules = [],
        public array $missingModuleDefinitions = [],
        public array $environmentIntegrityFindings = [],
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
        return $this->blockingEnvironmentRequirements() === []
            && $this->missingModuleDefinitions === []
            && $this->environmentIntegrityFindings === [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'environment' => $this->environment,
            'client_key' => $this->clientKey,
            'enabled_modules' => $this->enabledModules,
            'module_selection' => [
                'known_modules' => $this->knownModules,
                'selected_modules' => $this->selectedModules,
                'missing_module_definitions' => $this->missingModuleDefinitions,
            ],
            'covered_owners' => $this->coveredOwners,
            'ready' => $this->ready(),
            'environment_requirements' => array_map(
                static fn (ResolvedEnvironmentRequirement $requirement): array => $requirement->toArray(),
                $this->environmentRequirements,
            ),
            'setup_steps' => array_map(
                static fn (ResolvedDeploymentSetupStep $step): array => $step->toArray(),
                $this->setupSteps,
            ),
            'unused_environment_keys' => $this->unusedEnvironmentKeys,
            'environment_integrity_findings' => array_map(
                static fn (EnvironmentIntegrityFinding $finding): array => $finding->toArray(),
                $this->environmentIntegrityFindings,
            ),
        ];
    }
}
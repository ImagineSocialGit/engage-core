<?php

namespace App\Support\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\DeploymentPlan;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use App\Support\Environment\EnvironmentVariableCatalog;
use App\Support\Modules\ModuleManager;
use InvalidArgumentException;

final class DeploymentPlanResolver
{
    /**
     * @param iterable<int, DeploymentPlanContributor> $contributors
     */
    public function __construct(
        private readonly iterable $contributors,
        private readonly EnvironmentFileRepository $environmentFiles,
        private readonly ModuleManager $modules,
    ) {}

    public function resolve(): DeploymentPlan
    {
        $requirements = [];
        $owners = [];

        foreach ($this->contributors as $contributor) {
            if (! $contributor instanceof DeploymentPlanContributor) {
                throw new InvalidArgumentException(sprintf(
                    'Deployment plan resolver received invalid contributor [%s].',
                    get_debug_type($contributor),
                ));
            }

            $owner = trim($contributor->owner());

            if ($owner === '') {
                throw new InvalidArgumentException(sprintf(
                    'Deployment plan contributor [%s] returned an empty owner.',
                    $contributor::class,
                ));
            }

            $owners[$owner] = true;

            foreach ($contributor->environmentRequirements() as $requirement) {
                if (! $requirement instanceof EnvironmentRequirement) {
                    throw new InvalidArgumentException(sprintf(
                        'Deployment plan contributor [%s] returned invalid environment requirement [%s].',
                        $contributor::class,
                        get_debug_type($requirement),
                    ));
                }

                $definition = EnvironmentVariableCatalog::definition($requirement->key);

                if ($definition->owner !== $owner) {
                    throw new InvalidArgumentException(sprintf(
                        'Deployment contributor owner [%s] cannot claim environment key [%s] owned by [%s].',
                        $owner,
                        $definition->key,
                        $definition->owner,
                    ));
                }

                if ($definition->secret && $requirement->expectedValue !== null) {
                    throw new InvalidArgumentException(
                        "Secret environment key [{$definition->key}] cannot declare an expected value in a deployment plan.",
                    );
                }

                if ($definition->secret && $requirement->allowedValues !== []) {
                    throw new InvalidArgumentException(
                        "Secret environment key [{$definition->key}] cannot declare allowed values in a deployment plan.",
                    );
                }

                if (isset($requirements[$definition->key])) {
                    throw new InvalidArgumentException(
                        "Environment key [{$definition->key}] was contributed more than once.",
                    );
                }

                $requirements[$definition->key] = [$owner, $definition, $requirement];
            }
        }

        $resolved = [];

        foreach ($requirements as [$owner, $definition, $requirement]) {
            $resolved[] = $this->resolveRequirement(
                owner: $owner,
                definition: $definition,
                requirement: $requirement,
            );
        }

        usort($resolved, static fn (
            ResolvedEnvironmentRequirement $left,
            ResolvedEnvironmentRequirement $right,
        ): int => [
            $left->definition->scope,
            $left->definition->key,
        ] <=> [
            $right->definition->scope,
            $right->definition->key,
        ]);

        $coveredOwners = array_keys($owners);
        sort($coveredOwners);

        return new DeploymentPlan(
            environment: app()->environment(),
            clientKey: (string) config('client.key', ''),
            enabledModules: $this->modules->enabledKeysWithDependencies(),
            environmentRequirements: $resolved,
            unusedEnvironmentKeys: $this->unusedEnvironmentKeys(
                coveredOwners: $coveredOwners,
                claimedKeys: array_keys($requirements),
            ),
            coveredOwners: $coveredOwners,
        );
    }

    private function resolveRequirement(
        string $owner,
        EnvironmentVariableDefinition $definition,
        EnvironmentRequirement $requirement,
    ): ResolvedEnvironmentRequirement {
        $persistedValues = $this->environmentFiles->valuesForScope($definition->scope);
        $persisted = array_key_exists($definition->key, $persistedValues);
        $persistedValue = $persisted ? $persistedValues[$definition->key] : null;
        $hasPersistedValue = $persisted && $this->hasUsableValue($persistedValue);
        $mismatched = $hasPersistedValue
            && $requirement->expectedValue !== null
            && (string) $persistedValue !== $requirement->expectedValue;
        $invalid = $hasPersistedValue
            && $requirement->allowedValues !== []
            && ! in_array((string) $persistedValue, $requirement->allowedValues, true);

        $status = match (true) {
            $requirement->isRequired() && ! $persisted
                => ResolvedEnvironmentRequirement::STATUS_MISSING,
            $requirement->isRequired() && ! $hasPersistedValue
                => ResolvedEnvironmentRequirement::STATUS_UNRESOLVED,
            $requirement->isRequired() && $mismatched
                => ResolvedEnvironmentRequirement::STATUS_MISMATCH,
            $requirement->isRequired() && $invalid
                => ResolvedEnvironmentRequirement::STATUS_INVALID,
            $requirement->requirement === EnvironmentRequirement::DEFAULTED && ! $hasPersistedValue
                => ResolvedEnvironmentRequirement::STATUS_DEFAULT,
            $requirement->requirement === EnvironmentRequirement::OPTIONAL && ! $hasPersistedValue
                => ResolvedEnvironmentRequirement::STATUS_OPTIONAL,
            default => ResolvedEnvironmentRequirement::STATUS_READY,
        };

        return new ResolvedEnvironmentRequirement(
            definition: $definition,
            requirement: $requirement,
            owner: $owner,
            status: $status,
            targetPath: $this->displayPath($this->environmentFiles->pathForScope($definition->scope)),
            persisted: $persisted,
        );
    }

    /**
     * Only report unused keys for owners with an active contributor. This avoids
     * false positives while deployment coverage is being added module-by-module.
     *
     * @param array<int, string> $coveredOwners
     * @param array<int, string> $claimedKeys
     * @return array<int, string>
     */
    private function unusedEnvironmentKeys(array $coveredOwners, array $claimedKeys): array
    {
        $unused = [];
        $claimed = array_fill_keys($claimedKeys, true);
        $covered = array_fill_keys($coveredOwners, true);

        foreach ([
            EnvironmentVariableDefinition::SCOPE_ROOT,
            EnvironmentVariableDefinition::SCOPE_CLIENT,
        ] as $scope) {
            foreach ($this->environmentFiles->valuesForScope($scope) as $key => $_value) {
                if (isset($claimed[$key]) || ! EnvironmentVariableCatalog::has($key)) {
                    continue;
                }

                $definition = EnvironmentVariableCatalog::definition($key);

                if (isset($covered[$definition->owner])) {
                    $unused[] = $key;
                }
            }
        }

        sort($unused);

        return array_values(array_unique($unused));
    }

    private function hasUsableValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return ! is_string($value) || trim($value) !== '';
    }

    private function displayPath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base)
            ? substr($path, strlen($base))
            : $path;
    }
}
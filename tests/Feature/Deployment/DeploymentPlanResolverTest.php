<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use InvalidArgumentException;
use Tests\TestCase;

class DeploymentPlanResolverTest extends TestCase
{
    public function test_required_persisted_blank_value_is_unresolved(): void
    {
        $repository = $this->repository([
            'root' => ['APP_KEY' => ''],
            'client' => [],
        ]);

        $plan = (new DeploymentPlanResolver(
            contributors: [$this->contributor('core', [
                EnvironmentRequirement::required('APP_KEY', 'Required for encryption.'),
            ])],
            environmentFiles: $repository,
            modules: new ModuleManager(),
        ))->resolve();

        $this->assertFalse($plan->ready());
        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_UNRESOLVED,
            $plan->environmentRequirements[0]->status,
        );
    }

    public function test_required_key_missing_from_target_file_is_missing_even_when_process_value_exists(): void
    {
        $original = getenv('APP_ENV');
        $originalEnv = $_ENV['APP_ENV'] ?? null;
        $originalServer = $_SERVER['APP_ENV'] ?? null;

        putenv('APP_ENV=staging');
        $_ENV['APP_ENV'] = 'staging';
        $_SERVER['APP_ENV'] = 'staging';

        try {
            $plan = (new DeploymentPlanResolver(
                contributors: [$this->contributor('core', [
                    EnvironmentRequirement::required('APP_ENV', 'Environment must be explicit.'),
                ])],
                environmentFiles: $this->repository([
                    'root' => [],
                    'client' => [],
                ]),
                modules: new ModuleManager(),
            ))->resolve();

            $this->assertSame(
                ResolvedEnvironmentRequirement::STATUS_MISSING,
                $plan->environmentRequirements[0]->status,
            );
        } finally {
            $original === false
                ? putenv('APP_ENV')
                : putenv('APP_ENV='.$original);

            if ($originalEnv === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $originalEnv;
            }

            if ($originalServer === null) {
                unset($_SERVER['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $originalServer;
            }
        }
    }


    public function test_required_expected_value_mismatch_blocks_deployment(): void
    {
        $plan = (new DeploymentPlanResolver(
            contributors: [$this->contributor('core', [
                EnvironmentRequirement::required(
                    'CLIENT_KEY',
                    'Selected client must match persisted runtime identity.',
                    expectedValue: 'new-client',
                ),
            ])],
            environmentFiles: $this->repository([
                'root' => ['CLIENT_KEY' => 'old-client'],
                'client' => [],
            ]),
            modules: new ModuleManager(),
        ))->resolve();

        $this->assertFalse($plan->ready());
        $this->assertSame(
            ResolvedEnvironmentRequirement::STATUS_MISMATCH,
            $plan->environmentRequirements[0]->status,
        );
    }

    public function test_secret_requirement_cannot_expose_an_expected_value(): void
    {
        $resolver = new DeploymentPlanResolver(
            contributors: [$this->contributor('core', [
                EnvironmentRequirement::required(
                    'APP_KEY',
                    'Secret comparison must never be encoded in the plan.',
                    expectedValue: 'do-not-expose',
                ),
            ])],
            environmentFiles: $this->repository(['root' => [], 'client' => []]),
            modules: new ModuleManager(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Secret environment key [APP_KEY] cannot declare an expected value in a deployment plan.',
        );

        $resolver->resolve();
    }

    public function test_unused_detection_is_limited_to_owners_with_active_contributor_coverage(): void
    {
        $repository = $this->repository([
            'root' => [],
            'client' => [
                'PROJECT_STATE_ADMIN_EMAIL' => 'owner@example.test',
                'RESEND_API_KEY' => 'keep-until-messaging-contributor-exists',
            ],
        ]);

        $plan = (new DeploymentPlanResolver(
            contributors: [$this->contributor('core', [
                EnvironmentRequirement::required('APP_KEY', 'Required for encryption.'),
            ])],
            environmentFiles: $repository,
            modules: new ModuleManager(),
        ))->resolve();

        $this->assertContains('PROJECT_STATE_ADMIN_EMAIL', $plan->unusedEnvironmentKeys);
        $this->assertNotContains('RESEND_API_KEY', $plan->unusedEnvironmentKeys);
    }

    public function test_contributor_cannot_claim_another_owner_environment_key(): void
    {
        $resolver = new DeploymentPlanResolver(
            contributors: [$this->contributor('forms', [
                EnvironmentRequirement::required('APP_KEY', 'Invalid ownership claim.'),
            ])],
            environmentFiles: $this->repository(['root' => [], 'client' => []]),
            modules: new ModuleManager(),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Deployment contributor owner [forms] cannot claim environment key [APP_KEY] owned by [core].',
        );

        $resolver->resolve();
    }

    /**
     * @param array{root:array<string,string>,client:array<string,string>} $values
     */
    private function repository(array $values): EnvironmentFileRepository
    {
        return new class ($values) extends EnvironmentFileRepository
        {
            /** @param array{root:array<string,string>,client:array<string,string>} $values */
            public function __construct(private readonly array $values) {}

            public function pathForScope(string $scope): string
            {
                return base_path($scope === 'root' ? '.env' : 'client/test-client/.env');
            }

            public function valuesForScope(string $scope): array
            {
                return $this->values[$scope] ?? [];
            }
        };
    }

    /**
     * @param array<int, EnvironmentRequirement> $requirements
     */
    private function contributor(string $owner, array $requirements): DeploymentPlanContributor
    {
        return new class ($owner, $requirements) implements DeploymentPlanContributor
        {
            /** @param array<int, EnvironmentRequirement> $requirements */
            public function __construct(
                private readonly string $ownerValue,
                private readonly array $requirements,
            ) {}

            public function owner(): string
            {
                return $this->ownerValue;
            }

            public function environmentRequirements(): iterable
            {
                yield from $this->requirements;
            }
        };
    }
}
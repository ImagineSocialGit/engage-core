<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentEnvironmentDefinitionContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use App\Support\Environment\EnvironmentVariableCatalog;
use App\Support\Modules\ModuleManager;
use InvalidArgumentException;
use Tests\TestCase;

final class DynamicEnvironmentDefinitionContributorTest extends TestCase
{
    private const KEY = 'DONATIONS_EXTERNAL_CHECKOUT_CALLER_0123456789ABCDEF0123456789ABCDEF_SIGNING_SECRET';

    public function test_tagged_package_can_declare_selected_client_secret_without_static_catalog_change(): void
    {
        $this->register($this->contributor('donations', [
            $this->definition(self::KEY),
        ], [EnvironmentRequirement::required(self::KEY, 'Selected external caller requires signing.') ]));

        $definition = EnvironmentVariableCatalog::definition(self::KEY);

        $this->assertSame('donations', $definition->owner);
        $this->assertSame('client', $definition->scope);
        $this->assertTrue($definition->secret);
        $this->assertNotContains(self::KEY, EnvironmentVariableCatalog::builtInKeys());
        $this->assertArrayNotHasKey(self::KEY, EnvironmentVariableCatalog::templateDefinitions());
    }

    public function test_untagged_package_cannot_register_its_requirement(): void
    {
        $contributor = $this->contributor('donations', [
            $this->definition(self::KEY),
        ], [EnvironmentRequirement::required(self::KEY, 'Missing signing credential.')]);

        $plan = $this->resolve([$contributor], []);

        $this->assertFalse($plan->ready());
        $this->assertSame([], $plan->environmentRequirements);
        $this->assertSame('unregistered_requirement', $plan->environmentIntegrityFindings[0]->code);
    }

    public function test_registered_required_secret_appears_as_missing_without_credential_value(): void
    {
        $contributor = $this->contributor('donations', [
            $this->definition(self::KEY),
        ], [EnvironmentRequirement::required(self::KEY, 'Selected caller requires a first-party secret.')]);
        $this->register($contributor);

        $plan = $this->resolve([$contributor], []);
        $report = $plan->toArray();

        $this->assertFalse($plan->ready());
        $this->assertSame('missing', $report['environment_requirements'][0]['status']);
        $this->assertTrue($report['environment_requirements'][0]['secret']);
        $this->assertSame('client', $report['environment_requirements'][0]['scope']);
        $this->assertNull($report['environment_requirements'][0]['expected_value']);
        $this->assertSame([], $report['environment_integrity_findings']);
    }

    public function test_registered_secret_is_ready_without_exposing_its_persisted_value(): void
    {
        $secret = str_repeat('a', 64);
        $contributor = $this->contributor('donations', [
            $this->definition(self::KEY),
        ], [EnvironmentRequirement::required(self::KEY, 'Internal signing.')]);
        $this->register($contributor);

        $report = $this->resolve([$contributor], [
            'client' => [self::KEY => $secret],
        ])->toArray();

        $this->assertTrue($report['ready']);
        $this->assertSame('ready', $report['environment_requirements'][0]['status']);
        $this->assertStringNotContainsString($secret, json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_wrong_scope_for_package_owned_key_blocks_deployment(): void
    {
        $contributor = $this->contributor('donations', [
            $this->definition(self::KEY),
        ], [EnvironmentRequirement::required(self::KEY, 'Internal signing.')]);
        $this->register($contributor);

        $report = $this->resolve([$contributor], [
            'root' => [self::KEY => str_repeat('a', 64)],
            'client' => [self::KEY => str_repeat('a', 64)],
        ])->toArray();

        $this->assertFalse($report['ready']);
        $this->assertSame('wrong_environment_scope', $report['environment_integrity_findings'][0]['code']);
        $this->assertSame('donations', $report['environment_integrity_findings'][0]['owner']);
    }

    public function test_unused_package_key_is_detected_from_same_catalog_snapshot(): void
    {
        $contributor = $this->contributor('donations', [$this->definition(self::KEY)], []);
        $this->register($contributor);

        $plan = $this->resolve([$contributor], [
            'client' => [self::KEY => str_repeat('a', 64)],
        ]);

        $this->assertSame([self::KEY], $plan->unusedEnvironmentKeys);
    }

    public function test_dynamic_key_cannot_override_existing_platform_ownership(): void
    {
        $this->register($this->contributor('donations', [
            new EnvironmentVariableDefinition('APP_KEY', 'root', 'donations', true),
        ]));

        $this->expectException(InvalidArgumentException::class);
        EnvironmentVariableCatalog::definitions();
    }

    public function test_duplicate_dynamic_key_registration_is_rejected(): void
    {
        $this->register($this->contributor('donations', [$this->definition(self::KEY)]), 'dynamic.one');
        $this->register($this->contributor('donations', [$this->definition(self::KEY)]), 'dynamic.two');

        $this->expectException(InvalidArgumentException::class);
        EnvironmentVariableCatalog::definitions();
    }

    public function test_dynamic_definition_cannot_claim_other_owner(): void
    {
        $this->register($this->contributor('donations', [
            new EnvironmentVariableDefinition(self::KEY, 'client', 'forms', true),
        ]));

        $this->expectException(InvalidArgumentException::class);
        EnvironmentVariableCatalog::definitions();
    }

    public function test_dynamic_contributor_must_return_environment_definition_objects(): void
    {
        $this->register($this->contributor('donations', ['untrusted_value']));

        $this->expectException(InvalidArgumentException::class);
        EnvironmentVariableCatalog::definitions();
    }

    public function test_dynamic_secret_cannot_declare_expected_plaintext_value(): void
    {
        $contributor = $this->contributor('donations', [$this->definition(self::KEY)], [
            EnvironmentRequirement::required(self::KEY, 'Internal signing.', expectedValue: 'do-not-log-me'),
        ]);
        $this->register($contributor);

        $this->expectException(InvalidArgumentException::class);
        $this->resolve([$contributor], []);
    }

    private function definition(string $key): EnvironmentVariableDefinition
    {
        return new EnvironmentVariableDefinition($key, 'client', 'donations', true);
    }

    /**
     * @param array<int, mixed> $definitions
     * @param array<int, EnvironmentRequirement> $requirements
     */
    private function contributor(string $owner, array $definitions, array $requirements = []): DeploymentEnvironmentDefinitionContributor
    {
        return new class ($owner, $definitions, $requirements) implements DeploymentEnvironmentDefinitionContributor
        {
            public function __construct(
                private readonly string $moduleOwner,
                private readonly array $definitions,
                private readonly array $requirements,
            ) {}

            public function owner(): string
            {
                return $this->moduleOwner;
            }

            public function environmentDefinitions(): iterable
            {
                return $this->definitions;
            }

            public function environmentRequirements(): iterable
            {
                return $this->requirements;
            }
        };
    }

    private function register(DeploymentEnvironmentDefinitionContributor $contributor, string $name = 'test.dynamic.definition'): void
    {
        $this->app->instance($name, $contributor);
        $this->app->tag([$name], 'deployment.plan_contributors');
    }

    /**
     * @param array<int, DeploymentEnvironmentDefinitionContributor> $contributors
     * @param array<string, array<string, string>> $values
     */
    private function resolve(array $contributors, array $values): \App\Support\Deployment\Data\DeploymentPlan
    {
        $repository = new class ($values) extends EnvironmentFileRepository
        {
            public function __construct(private readonly array $values) {}

            public function pathForScope(string $scope): string
            {
                return base_path($scope === 'root' ? '.env' : 'client/test-client/.env');
            }

            public function inspectScope(string $scope): array
            {
                return ['values' => $this->values[$scope] ?? [], 'issues' => []];
            }
        };

        $modules = new class extends ModuleManager
        {
            public function definitions(): array
            {
                return ['core' => [], 'donations' => []];
            }

            public function enabledKeys(): array
            {
                return ['core', 'donations'];
            }

            public function enabledKeysWithDependencies(): array
            {
                return ['core', 'donations'];
            }
        };

        return (new DeploymentPlanResolver($contributors, $repository, $modules))->resolve();
    }
}
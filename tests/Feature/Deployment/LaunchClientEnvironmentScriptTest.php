<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\Data\ResolvedEnvironmentRequirement;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class LaunchClientEnvironmentScriptTest extends TestCase
{
    public function test_launcher_and_support_helper_are_syntactically_valid(): void
    {
        (new Process([
            'bash',
            '-n',
            base_path('scripts/operations/launch-client-environment.sh'),
        ]))->mustRun();

        (new Process([
            'python3',
            '-m',
            'py_compile',
            base_path('scripts/operations/lib/launch_client_environment.py'),
        ]))->mustRun();

        $this->assertTrue(true);
    }

    public function test_production_identity_is_derived_from_client_repo_and_root_domain(): void
    {
        $identity = $this->derive(
            environment: 'production',
            repo: 'git@github.com:ImagineSocialGit/slam-dunk-crm.git',
            domain: 'slamdunkhomeloans.com',
        );

        $this->assertSame('slam-dunk-crm', $identity['client_key']);
        $this->assertSame('slam_dunk_crm', $identity['runtime_prefix_stem']);
        $this->assertSame('slam_dunk_crm_', $identity['redis_prefix']);
        $this->assertSame('slam_dunk_crm_cache_', $identity['cache_prefix']);
        $this->assertSame('slam_dunk_crm_horizon:', $identity['horizon_prefix']);
        $this->assertSame('slamdunkhomeloans.com-horizon', $identity['horizon_program']);
        $this->assertSame(
            '/var/www/slamdunkhomeloans.com/engage-core',
            $identity['app_path'],
        );
        $this->assertSame('crm.slamdunkhomeloans.com', $identity['crm_host']);
    }

    public function test_staging_identity_gets_an_environment_specific_runtime_namespace(): void
    {
        $identity = $this->derive(
            environment: 'staging',
            repo: 'git@github.com:ImagineSocialGit/slam-dunk-crm.git',
            domain: 'staging.slamdunkhomeloans.com',
        );

        $this->assertSame('slam_dunk_crm_staging', $identity['runtime_prefix_stem']);
        $this->assertSame('slam_dunk_crm_staging_', $identity['redis_prefix']);
        $this->assertSame('slam_dunk_crm_staging_cache_', $identity['cache_prefix']);
        $this->assertSame('slam_dunk_crm_staging_horizon:', $identity['horizon_prefix']);
        $this->assertSame(
            'staging.slamdunkhomeloans.com-horizon',
            $identity['horizon_program'],
        );
    }

    public function test_mysql_application_user_derivation_is_deterministically_bounded(): void
    {
        $first = $this->derive(
            environment: 'production',
            repo: 'git@github.com:ImagineSocialGit/this-is-an-extremely-long-client-repository-name-for-core.git',
            domain: 'example.com',
        );
        $second = $this->derive(
            environment: 'production',
            repo: 'git@github.com:ImagineSocialGit/this-is-an-extremely-long-client-repository-name-for-core.git',
            domain: 'example.com',
        );

        $this->assertLessThanOrEqual(32, strlen($first['database_user']));
        $this->assertSame($first['database_user'], $second['database_user']);
    }

    public function test_launcher_consumes_application_plan_instead_of_an_operator_queue_list(): void
    {
        $launcher = (string) file_get_contents(
            base_path('scripts/operations/launch-client-environment.sh'),
        );

        $this->assertStringContainsString('engage:deployment-plan --json', $launcher);
        $this->assertStringContainsString('run-setup-steps', $launcher);
        $this->assertStringContainsString('modules:install', $launcher);
        $this->assertStringNotContainsString('HORIZON_PRIMARY_QUEUES', $launcher);
    }

    public function test_machine_requirement_json_exposes_non_secret_expected_value(): void
    {
        $resolved = new ResolvedEnvironmentRequirement(
            definition: new EnvironmentVariableDefinition(
                key: 'APP_ENV',
                scope: EnvironmentVariableDefinition::SCOPE_ROOT,
                owner: 'core',
            ),
            requirement: EnvironmentRequirement::required(
                'APP_ENV',
                'Environment is explicit.',
                expectedValue: 'production',
            ),
            owner: 'core',
            status: ResolvedEnvironmentRequirement::STATUS_MISMATCH,
            targetPath: '.env',
            persisted: true,
        );

        $this->assertSame('production', $resolved->toArray()['expected_value']);
    }

    /** @return array<string, mixed> */
    private function derive(string $environment, string $repo, string $domain): array
    {
        $process = new Process([
            'python3',
            base_path('scripts/operations/lib/launch_client_environment.py'),
            'derive',
            '--environment',
            $environment,
            '--client-repo',
            $repo,
            '--root-domain',
            $domain,
        ]);
        $process->mustRun();

        $decoded = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
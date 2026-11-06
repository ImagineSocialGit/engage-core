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
        foreach ([
            'scripts/operations/launch-client-environment.sh',
            'scripts/operations/lib/launch_source.sh',
            'scripts/operations/lib/launch_host_web.sh',
            'scripts/operations/lib/launch_environment.sh',
            'scripts/operations/lib/launch_runtime.sh',
            'scripts/operations/lib/launch_verify.sh',
            'scripts/operations/lib/launch_application.sh',
            'scripts/operations/lib/launch_workflow.sh',
            'scripts/operations/lib/launch_audit.sh',
            'scripts/operations/lib/launch_host_repair.sh',
            'scripts/operations/lib/launch_fix.sh',
        ] as $path) {
            (new Process(['bash', '-n', base_path($path)]))->mustRun();
        }

        (new Process([
            'python3',
            '-m',
            'py_compile',
            base_path('scripts/operations/lib/launch_client_environment.py'),
        ]))->mustRun();

        $this->assertTrue(true);
    }

    public function test_source_checkout_and_host_web_operations_are_separate_import_only_phases(): void
    {
        $launcher = (string) file_get_contents(
            base_path('scripts/operations/launch-client-environment.sh'),
        );
        $source = (string) file_get_contents(
            base_path('scripts/operations/lib/launch_source.sh'),
        );
        $hostWeb = (string) file_get_contents(
            base_path('scripts/operations/lib/launch_host_web.sh'),
        );

        $this->assertStringContainsString('launch_source.sh launch_host_web.sh', $launcher);
        $this->assertStringNotContainsString('ensure_source() {', $launcher);
        $this->assertStringNotContainsString('install_nginx_site() {', $launcher);
        $this->assertStringNotContainsString('ensure_dns_and_tls() {', $launcher);
        $this->assertStringContainsString('repo_must_be_clean() {', $source);
        $this->assertStringContainsString('repo_pull_ff() {', $source);
        $this->assertStringContainsString('ensure_source() {', $source);
        $this->assertStringContainsString('core_hosts() {', $hostWeb);
        $this->assertStringContainsString('install_nginx_site() {', $hostWeb);
        $this->assertStringContainsString('ensure_dns_and_tls() {', $hostWeb);
        $this->assertStringNotContainsString('install_nginx_site() {', $source);

        // Import alone may neither fetch repositories nor change host services.
        $command = 'set -euo pipefail; for file in "$@"; do source "$file"; done; '
            .'declare -F repo_pull_ff ensure_source core_hosts install_nginx_site ensure_dns_and_tls >/dev/null';
        $process = new Process([
            'bash', '-c', $command, 'bash',
            base_path('scripts/operations/lib/launch_source.sh'),
            base_path('scripts/operations/lib/launch_host_web.sh'),
        ]);
        $process->mustRun();
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_environment_runtime_and_verification_are_isolated_and_import_only(): void
    {
        $launcher = (string) file_get_contents(
            base_path('scripts/operations/launch-client-environment.sh'),
        );
        $environment = (string) file_get_contents(
            base_path('scripts/operations/lib/launch_environment.sh'),
        );
        $runtime = (string) file_get_contents(
            base_path('scripts/operations/lib/launch_runtime.sh'),
        );
        $verification = (string) file_get_contents(
            base_path('scripts/operations/lib/launch_verify.sh'),
        );

        $this->assertStringContainsString('launch_environment.sh launch_runtime.sh launch_verify.sh', $launcher);
        $this->assertStringNotContainsString('ensure_environment() {', $launcher);
        $this->assertStringNotContainsString('ensure_runtime() {', $launcher);
        $this->assertStringNotContainsString('verify_runtime() {', $launcher);
        $this->assertStringContainsString('ensure_environment() {', $environment);
        $this->assertStringContainsString('ensure_env_permissions() {', $environment);
        $this->assertStringContainsString('ensure_runtime() {', $runtime);
        $this->assertStringContainsString('install_supervisor_program() {', $runtime);
        $this->assertStringContainsString('verify_runtime() {', $verification);

        $command = 'set -euo pipefail; for file in "$@"; do source "$file"; done; '
            .'declare -F ensure_environment ensure_env_permissions ensure_runtime verify_runtime >/dev/null';
        $process = new Process([
            'bash', '-c', $command, 'bash',
            base_path('scripts/operations/lib/launch_environment.sh'),
            base_path('scripts/operations/lib/launch_runtime.sh'),
            base_path('scripts/operations/lib/launch_verify.sh'),
        ]);
        $process->mustRun();
        $this->assertSame('', $process->getOutput());
        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_guided_fix_is_delegated_and_category_aliases_are_resolved_without_mutation(): void
    {
        $launcher = (string) file_get_contents(
            base_path('scripts/operations/launch-client-environment.sh'),
        );
        $fix = (string) file_get_contents(
            base_path('scripts/operations/lib/launch_fix.sh'),
        );
        $hostRepair = (string) file_get_contents(
            base_path('scripts/operations/lib/launch_host_repair.sh'),
        );

        $this->assertStringContainsString('launch_host_repair.sh launch_fix.sh', $launcher);
        $this->assertStringNotContainsString('parse_fix() {', $launcher);
        $this->assertStringNotContainsString('fix_apply_nginx_host() {', $launcher);
        $this->assertStringContainsString('parse_fix() {', $fix);
        $this->assertStringContainsString('run_fix() {', $fix);
        $this->assertStringContainsString('fix_apply_nginx_host() {', $hostRepair);

        $command = 'source "$1"; normalize_fix_categories "tls,cron,providers,schema,tls"';
        $process = new Process([
            'bash', '-c', $command, 'bash', base_path('scripts/operations/lib/launch_fix.sh'),
        ]);
        $process->mustRun();

        $this->assertSame("nginx,scheduler,environment,schema\n", $process->getOutput());

        $invalid = new Process([
            'bash', '-c', 'source "$1"; normalize_fix_categories "unknown"',
            'bash', base_path('scripts/operations/lib/launch_fix.sh'),
        ]);
        $invalid->run();
        $this->assertNotSame(0, $invalid->getExitCode());
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

    public function test_launcher_delegates_application_operations_to_the_applicable_phases(): void
    {
        $launcher = (string) file_get_contents(
            base_path('scripts/operations/launch-client-environment.sh'),
        );
        $application = (string) file_get_contents(
            base_path('scripts/operations/lib/launch_application.sh'),
        );
        $workflow = (string) file_get_contents(
            base_path('scripts/operations/lib/launch_workflow.sh'),
        );

        $this->assertStringContainsString('launch_application.sh launch_workflow.sh', $launcher);
        $this->assertStringNotContainsString('install_added_modules() {', $launcher);
        $this->assertStringNotContainsString('run_new_or_resume() {', $launcher);
        $this->assertStringContainsString('engage:deployment-plan --json', $application);
        $this->assertStringContainsString('run-setup-steps', $application);
        $this->assertStringContainsString('modules:install', $application);
        $this->assertStringContainsString('modules:preflight', $application);
        $this->assertStringNotContainsString('HORIZON_PRIMARY_QUEUES', $application);
        $this->assertStringContainsString('resolve_deployment_plan', $workflow);
        $this->assertStringContainsString('install_added_modules', $workflow);

        $updateStart = strpos($application, 'update_application() {');
        $updateEnd = strpos($application, 'install_added_modules() {', $updateStart);

        $this->assertNotFalse($updateStart);
        $this->assertNotFalse($updateEnd);

        $update = substr($application, $updateStart, $updateEnd - $updateStart);
        $platformMigrationPosition = strpos($update, 'artisan migrate --force');
        $preflightPosition = strpos($update, 'artisan modules:preflight');
        $moduleMigrationPosition = strpos($update, 'artisan modules:migrate --force');

        $this->assertNotFalse($platformMigrationPosition);
        $this->assertNotFalse($preflightPosition);
        $this->assertNotFalse($moduleMigrationPosition);
        $this->assertLessThan($preflightPosition, $platformMigrationPosition);
        $this->assertLessThan($moduleMigrationPosition, $preflightPosition);
    }

    public function test_selected_module_installation_is_gated_by_the_application_plan(): void
    {
        $application = (string) file_get_contents(
            base_path('scripts/operations/lib/launch_application.sh'),
        );

        $start = strpos($application, 'install_added_modules() {');
        $this->assertNotFalse($start);
        $install = substr($application, $start);

        $this->assertStringContainsString('module_enabled "$module"', $install);
        $this->assertStringContainsString('modules:install "$module" --force', $install);
        $this->assertStringNotContainsString('case "$module"', $install);
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
<?php

namespace Tests\Feature\Deployment;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class LaunchClientEnvironmentAuditScriptTest extends TestCase
{
    public function test_audit_identity_uses_the_same_canonical_runtime_naming_without_creating_state(): void
    {
        $process = new Process([
            'python3',
            base_path('scripts/operations/lib/launch_client_environment.py'),
            'derive-audit',
            '--environment',
            'staging',
            '--client-key',
            'slam-dunk-crm',
            '--root-domain',
            'staging.slamdunkhomeloans.com',
        ]);
        $process->mustRun();

        $identity = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            '/var/www/staging.slamdunkhomeloans.com/engage-core',
            $identity['app_path'],
        );
        $this->assertSame('slam_dunk_crm_staging', $identity['database_name']);
        $this->assertSame('slam_dunk_crm_staging', $identity['database_user']);
        $this->assertSame('slam_dunk_crm_staging_', $identity['redis_prefix']);
        $this->assertSame('slam_dunk_crm_staging_cache_', $identity['cache_prefix']);
        $this->assertSame('slam_dunk_crm_staging_horizon:', $identity['horizon_prefix']);
        $this->assertSame(
            'staging.slamdunkhomeloans.com-horizon',
            $identity['horizon_program'],
        );
        $this->assertArrayNotHasKey('state_file', $identity);
    }

    public function test_plan_audit_reports_blockers_without_exposing_environment_values(): void
    {
        $plan = [
            'enabled_modules' => ['core', 'messaging'],
            'environment_requirements' => [
                [
                    'key' => 'RESEND_API_KEY',
                    'scope' => 'client',
                    'owner' => 'messaging',
                    'secret' => true,
                    'requirement' => 'required',
                    'reason' => 'Live Resend delivery requires the client API key.',
                    'status' => 'unresolved',
                    'actual_value' => 'secret-value',
                ],
            ],
            'setup_steps' => [
                [
                    'key' => 'messaging.resend',
                    'title' => 'Resend email delivery',
                    'verification' => [
                        'Send one provider-safe email and verify the lifecycle webhook.',
                    ],
                ],
            ],
            'unused_environment_keys' => [],
        ];

        $process = new Process([
            'python3',
            base_path('scripts/operations/lib/launch_client_environment.py'),
            'plan-audit',
        ]);
        $process->setInput(json_encode($plan, JSON_THROW_ON_ERROR));
        $process->mustRun();

        $output = $process->getOutput();

        $this->assertStringContainsString(
            "BREAKING\tdeployment_plan.RESEND_API_KEY\t",
            $output,
        );
        $this->assertStringContainsString(
            "MANUAL VERIFICATION REQUIRED\texternal_setup.messaging.resend\t",
            $output,
        );
        $this->assertStringNotContainsString('secret-value', $output);
    }

    public function test_audit_shell_region_contains_no_known_mutating_launcher_operations(): void
    {
        $launcher = (string) file_get_contents(
            base_path('scripts/operations/launch-client-environment.sh'),
        );

        $start = strpos($launcher, 'audit_reset() {');
        $end = strpos($launcher, 'http_status() {', $start);

        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $auditRegion = substr($launcher, $start, $end - $start);

        foreach ([
            'env_set ',
            'env_remove ',
            'state_set ',
            'state_set_json ',
            'mark_phase ',
            'ensure_env_permissions',
            'runtime_permission_setup',
            'install_nginx_site',
            'ensure_dns_and_tls',
            'install_supervisor_program',
            'install_scheduler_cron',
            'engage:environment:sync',
            'chown ',
            'chmod ',
            'systemctl reload',
            'supervisorctl restart',
            'certbot ',
            'modules:install',
            'modules:migrate',
            'artisan migrate',
            'artisan optimize:clear',
        ] as $mutator) {
            $this->assertStringNotContainsString(
                $mutator,
                $auditRegion,
                "Audit region unexpectedly contains mutating operation [{$mutator}].",
            );
        }
    }

    public function test_audit_is_state_file_independent_in_launcher_dispatch(): void
    {
        $launcher = (string) file_get_contents(
            base_path('scripts/operations/launch-client-environment.sh'),
        );

        $this->assertStringContainsString(
            'launch-client-environment.sh audit --environment ENV --client-key KEY --root-domain DOMAIN [options]',
            $launcher,
        );
        $this->assertStringContainsString(
            "audit)\n            parse_audit \"\$@\"",
            $launcher,
        );

        $start = strpos($launcher, 'parse_audit() {');
        $end = strpos($launcher, 'http_status() {', $start);
        $auditParser = substr($launcher, $start, $end - $start);

        $this->assertStringNotContainsString('STATE_FILE=', $auditParser);
        $this->assertStringNotContainsString('state-init', $auditParser);
    }


    public function test_nginx_host_owner_helper_reports_the_server_block_root(): void
    {
        $directory = storage_path('framework/testing/deployment-audit-nginx');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }

        file_put_contents(
            $directory.'/legacy-client',
            <<<'NGINX'
server {
    listen 443 ssl;
    server_name crm.staging.example.com webhooks.staging.example.com;
    root /var/www/staging/example/legacy-core/public;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
}

server {
    listen 80;
    server_name crm.staging.example.com webhooks.staging.example.com;
    return 301 https://$host$request_uri;
}
NGINX,
        );

        $process = new Process([
            'python3',
            base_path('scripts/operations/lib/launch_client_environment.py'),
            'nginx-host-owners',
            '--directory',
            $directory,
            '--host',
            'crm.staging.example.com',
        ]);
        $process->mustRun();

        $owners = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $owners);
        $this->assertSame(
            '/var/www/staging/example/legacy-core/public',
            $owners[0]['root'],
        );
    }

    public function test_nginx_dump_owner_helper_separates_main_site_from_core_and_ignores_redirect_blocks(): void
    {
        $configDump = <<<'NGINX'
# configuration file /etc/nginx/sites-enabled/staging.example.com:
server {
    listen 443 ssl;
    server_name staging.example.com;
    root /var/www/staging/example-seo/public;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}

server {
    listen 80;
    server_name staging.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name crm.staging.example.com webhooks.staging.example.com;
    root /var/www/staging/staging.example.com/engage-core/public;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}

server {
    listen 80;
    server_name crm.staging.example.com webhooks.staging.example.com;
    return 301 https://$host$request_uri;
}
NGINX;

        $crmProcess = new Process([
            'python3',
            base_path('scripts/operations/lib/launch_client_environment.py'),
            'nginx-host-owners-stdin',
            '--host',
            'crm.staging.example.com',
        ]);
        $crmProcess->setInput($configDump);
        $crmProcess->mustRun();

        $crmOwners = json_decode(
            $crmProcess->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertCount(1, $crmOwners);
        $this->assertSame(
            '/var/www/staging/staging.example.com/engage-core/public',
            $crmOwners[0]['root'],
        );
        $this->assertSame(
            'unix:/run/php/php8.3-fpm.sock',
            $crmOwners[0]['fastcgi_pass'],
        );

        $rootProcess = new Process([
            'python3',
            base_path('scripts/operations/lib/launch_client_environment.py'),
            'nginx-host-owners-stdin',
            '--host',
            'staging.example.com',
        ]);
        $rootProcess->setInput($configDump);
        $rootProcess->mustRun();

        $rootOwners = json_decode(
            $rootProcess->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertCount(1, $rootOwners);
        $this->assertSame(
            '/var/www/staging/example-seo/public',
            $rootOwners[0]['root'],
        );
    }

    public function test_environment_collision_map_scans_multiple_prefixes_in_one_pass(): void
    {
        $directory = storage_path('framework/testing/deployment-audit-env-collisions');
        $first = $directory.'/first';
        $second = $directory.'/second';

        if (! is_dir($first)) {
            mkdir($first, 0777, true);
        }
        if (! is_dir($second)) {
            mkdir($second, 0777, true);
        }

        file_put_contents(
            $first.'/.env',
            "CACHE_PREFIX=client_cache_\nREDIS_PREFIX=client_\nHORIZON_PREFIX=client_horizon:\n",
        );
        file_put_contents(
            $second.'/.env',
            "CACHE_PREFIX=client_cache_\nREDIS_PREFIX=other_\nHORIZON_PREFIX=client_horizon:\n",
        );

        $process = new Process([
            'python3',
            base_path('scripts/operations/lib/launch_client_environment.py'),
            'env-collision-map',
            '--search-root',
            $directory,
            '--query',
            'CACHE_PREFIX=client_cache_',
            '--query',
            'REDIS_PREFIX=client_',
            '--query',
            'HORIZON_PREFIX=client_horizon:',
            '--exclude',
            $first.'/.env',
        ]);
        $process->mustRun();

        $collisions = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([$second.'/.env'], $collisions['CACHE_PREFIX']);
        $this->assertSame([], $collisions['REDIS_PREFIX']);
        $this->assertSame([$second.'/.env'], $collisions['HORIZON_PREFIX']);
    }

    public function test_audit_distinguishes_legacy_drift_from_breaking_runtime_failures(): void
    {
        $launcher = (string) file_get_contents(
            base_path('scripts/operations/launch-client-environment.sh'),
        );

        $this->assertStringContainsString(
            'Fix eligibility: only BREAKING findings are candidates for automatic remediation.',
            $launcher,
        );
        $this->assertStringContainsString(
            'Legacy path drift only: new deployments use',
            $launcher,
        );
        $this->assertStringContainsString(
            'Do not normalize a functional existing identity.',
            $launcher,
        );
        $this->assertStringContainsString(
            'File duplication alone does not prove concurrent Redis use',
            $launcher,
        );
        $this->assertStringContainsString(
            'env-collision-map',
            $launcher,
        );
        $this->assertStringContainsString(
            'uses the Core default',
            $launcher,
        );
        $this->assertStringContainsString(
            'This is runtime ownership/cutover state, not an automatic fix.',
            $launcher,
        );
        $this->assertStringContainsString(
            'Application-level checks are unavailable until a deliberate cutover is prepared.',
            $launcher,
        );
        $this->assertStringContainsString(
            'nginx-host-owners-stdin',
            $launcher,
        );
        $this->assertStringContainsString(
            'Root/client environment loading contract passed before Laravel bootstrap.',
            $launcher,
        );
        $this->assertStringContainsString(
            'The certificate served locally for',
            $launcher,
        );
        $this->assertStringContainsString(
            'is served separately from Core',
            $launcher,
        );

        $auditStart = strpos($launcher, 'audit_reset() {');
        $auditEnd = strpos($launcher, 'http_status() {', $auditStart);
        $auditRegion = substr($launcher, $auditStart, $auditEnd - $auditStart);

        $this->assertStringNotContainsString('MISMATCH', $auditRegion);
        $this->assertStringNotContainsString('MISSING', $auditRegion);
        $this->assertStringContainsString('audit_result INFO supervisor.program', $auditRegion);
        $this->assertStringContainsString('audit_result BREAKING horizon.process', $auditRegion);
        $this->assertStringContainsString('audit_result BREAKING scheduler.cron', $auditRegion);
        $this->assertStringContainsString('audit_result BREAKING http.crm', $auditRegion);
    }

    public function test_module_status_audit_reports_pre_ledger_enabled_schema_breaks_without_disabled_scope_noise(): void
    {
        $payload = [
            'platform' => [
                'repository_exists' => true,
                'ledger_exists' => false,
            ],
            'scopes' => [
                [
                    'module_key' => 'core',
                    'migration_state' => 'partial',
                    'progress' => '6/12',
                    'pending_migrations' => ['2026_08_19_161800_create_contact_import_occurrences_table.php'],
                    'ledger_status' => 'ledger_missing',
                    'contract_state' => 'unavailable',
                ],
                [
                    'module_key' => 'tasks',
                    'migration_state' => 'current',
                    'progress' => '3/3',
                    'pending_migrations' => [],
                    'ledger_status' => 'ledger_missing',
                    'contract_state' => 'unavailable',
                ],
            ],
        ];

        $process = new Process([
            'python3',
            base_path('scripts/operations/lib/launch_client_environment.py'),
            'module-status-audit',
        ]);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->mustRun();

        $output = $process->getOutput();

        $this->assertStringContainsString(
            "BREAKING\tmodule_migrations.platform_ledger\t",
            $output,
        );
        $this->assertStringContainsString(
            "BREAKING\tmodule_migrations.core.schema\t",
            $output,
        );
        $this->assertStringContainsString(
            "PASS\tmodule_migrations.tasks.schema\t",
            $output,
        );
        $this->assertStringNotContainsString('media', $output);
        $this->assertStringNotContainsString('portal', $output);
    }

    public function test_module_status_fix_selection_includes_current_untracked_and_partial_enabled_scopes_only(): void
    {
        $payload = [
            'platform' => [
                'repository_exists' => true,
                'ledger_exists' => true,
            ],
            'scopes' => [
                [
                    'module_key' => 'core',
                    'migration_state' => 'partial',
                    'ledger_status' => 'untracked',
                    'contract_state' => 'untracked',
                ],
                [
                    'module_key' => 'tasks',
                    'migration_state' => 'current',
                    'ledger_status' => 'untracked',
                    'contract_state' => 'untracked',
                ],
                [
                    'module_key' => 'workflow',
                    'migration_state' => 'current',
                    'ledger_status' => 'installed',
                    'contract_state' => 'current',
                ],
            ],
        ];

        $process = new Process([
            'python3',
            base_path('scripts/operations/lib/launch_client_environment.py'),
            'module-status-fix-modules',
        ]);
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->mustRun();

        $this->assertSame(
            ['core', 'tasks'],
            array_values(array_filter(
                preg_split('/\R/', trim($process->getOutput())) ?: [],
            )),
        );
    }

    public function test_audit_proves_remote_source_current_before_interpreting_runtime_state(): void
    {
        $launcher = (string) file_get_contents(
            base_path('scripts/operations/launch-client-environment.sh'),
        );

        $this->assertStringContainsString(
            'git ls-remote',
            $launcher,
        );
        $this->assertStringNotContainsString(
            'GIT_TERMINAL_PROMPT=0',
            $launcher,
        );
        $this->assertStringNotContainsString(
            'BatchMode=yes',
            $launcher,
        );
        $this->assertStringContainsString(
            'Git reported:',
            $launcher,
        );
        $this->assertStringContainsString(
            'Local HEAD matches origin/$expected_branch.',
            $launcher,
        );
        $this->assertStringContainsString(
            'Authoritative runtime audit deferred: Core and client source must be clean and match their configured remote branches first.',
            $launcher,
        );
        $this->assertStringContainsString(
            'Source is never pulled automatically.',
            $launcher,
        );
    }

    public function test_fix_is_a_separate_breaking_only_dry_run_first_path(): void
    {
        $launcher = (string) file_get_contents(
            base_path('scripts/operations/launch-client-environment.sh'),
        );

        $this->assertStringContainsString(
            'launch-client-environment.sh fix --environment ENV --client-key KEY --root-domain DOMAIN [options]',
            $launcher,
        );
        $this->assertStringContainsString(
            "fix)\n            parse_fix \"\$@\"",
            $launcher,
        );
        $this->assertStringContainsString(
            'local mode="dry-run"',
            $launcher,
        );
        $this->assertStringContainsString(
            'Only BREAKING findings may enter the closed automatic fix registry.',
            $launcher,
        );
        $this->assertStringContainsString(
            '== Mandatory post-fix audit ==',
            $launcher,
        );
        $this->assertStringContainsString(
            'modules:install "$module" --force',
            $launcher,
        );
        $this->assertStringContainsString(
            'supplemental Core host',
            $launcher,
        );
        $this->assertStringContainsString(
            'Deferred Horizon repair: deployment plan/setup validation or effective runtime-directory access is not green yet.',
            $launcher,
        );
        $this->assertStringContainsString(
            'Deferred Scheduler repair: deployment plan/setup validation or effective runtime-directory access is not green yet.',
            $launcher,
        );
    }

}
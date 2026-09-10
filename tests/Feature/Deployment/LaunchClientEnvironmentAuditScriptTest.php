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
            "MISMATCH\tdeployment_plan.RESEND_API_KEY\t",
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
}
<?php

namespace Tests\Feature\Clients;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class BootstrapClientLocalScriptTest extends TestCase
{
    public function test_local_bootstrap_script_parses_as_valid_bash(): void
    {
        $process = new Process([
            'bash',
            '-n',
            base_path('scripts/bootstrap-client-local.sh'),
        ]);

        $process->mustRun();

        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_local_bootstrap_uses_the_canonical_dev_hosts(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $this->assertStringContainsString(
            'LOCAL_ROOT_DOMAIN="engagecore.test"',
            $script,
        );
        $this->assertStringContainsString(
            'LOCAL_APP_URL="http://engagecore.test"',
            $script,
        );
        $this->assertStringContainsString(
            'LOCAL_CRM_APP_URL="http://crm.engagecore.test"',
            $script,
        );
        $this->assertStringNotContainsString(
            'http://localhost',
            $script,
        );
        $this->assertStringNotContainsString(
            '--root-domain',
            $script,
        );
        $this->assertStringNotContainsString(
            '--app-url',
            $script,
        );
        $this->assertStringNotContainsString(
            '--crm-app-url',
            $script,
        );
    }

    public function test_local_bootstrap_is_guarded_and_does_not_manage_git_or_github(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $this->assertStringContainsString(
            'Local bootstrap requires APP_ENV=local',
            $script,
        );
        $this->assertStringContainsString(
            'RESOLVED_APP_ENV',
            $script,
        );

        $this->assertStringNotContainsString('gh repo create', $script);
        $this->assertStringNotContainsString('gh repo delete', $script);
        $this->assertStringNotContainsString('git push', $script);
        $this->assertStringNotContainsString('git commit', $script);
    }

    public function test_local_bootstrap_reproduces_the_proven_local_env_permission_contract(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $this->assertStringContainsString(
            'WEB_USER="${ENGAGE_CORE_WEB_USER:-www-data}"',
            $script,
        );
        $this->assertStringContainsString(
            'chmod 0664 "$ROOT_ENV" "$CLIENT_ENV"',
            $script,
        );
        $this->assertStringContainsString(
            'sudo -u "$WEB_USER" test -r "$ROOT_ENV"',
            $script,
        );
        $this->assertStringContainsString(
            'sudo -u "$WEB_USER" test -r "$CLIENT_ENV"',
            $script,
        );
        $this->assertStringNotContainsString('chmod 0640 "$CLIENT_ENV"', $script);
        $this->assertStringNotContainsString('chgrp "$WEB_GROUP"', $script);
        $this->assertStringNotContainsString('ENGAGE_CORE_WEB_GROUP', $script);
    }

    public function test_local_bootstrap_provisions_client_scoped_runtime_state(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        foreach ([
            'ROOT_DOMAIN',
            'APP_URL',
            'CRM_APP_URL',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
        ] as $key) {
            $this->assertStringContainsString($key, $script);
        }

        $this->assertStringContainsString(
            'DERIVED_DB_DATABASE="$(bounded_identifier "${RUNTIME_STEM}_local" 64)"',
            $script,
        );
        $this->assertStringContainsString(
            'DERIVED_DB_USERNAME="$(bounded_identifier "${RUNTIME_STEM}_local" 32)"',
            $script,
        );
        $this->assertStringContainsString('DB_DATABASE" == "laravel"', $script);
        $this->assertStringContainsString('DB_USERNAME" == "root"', $script);
        $this->assertStringContainsString('openssl rand -hex 20', $script);
        $this->assertStringContainsString('sudo mysql -p', $script);
        $this->assertStringContainsString('CREATE DATABASE IF NOT EXISTS', $script);
        $this->assertStringContainsString('CREATE USER IF NOT EXISTS', $script);
        $this->assertStringContainsString('GRANT ALL PRIVILEGES', $script);
    }

    public function test_target_runtime_is_built_and_made_web_readable_before_switching_client(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $permissionAndReadability = strpos(
            $script,
            "apply_local_env_permissions\nassert_local_env_readability\n\necho",
        );
        $databaseProvision = strpos($script, 'CREATE DATABASE IF NOT EXISTS');
        $selection = strpos($script, 'env_set "$ROOT_ENV" CLIENT_KEY "$CLIENT_KEY"');

        $this->assertNotFalse($permissionAndReadability);
        $this->assertNotFalse($databaseProvision);
        $this->assertNotFalse($selection);
        $this->assertLessThan($databaseProvision, $permissionAndReadability);
        $this->assertLessThan($selection, $databaseProvision);
    }

    public function test_permission_normalization_runs_after_atomic_client_selection_write(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $selection = strpos($script, 'env_set "$ROOT_ENV" CLIENT_KEY "$CLIENT_KEY"');
        $permissionApply = strpos($script, 'apply_local_env_permissions', $selection ?: 0);
        $readability = strpos($script, 'assert_local_env_readability', $permissionApply ?: 0);
        $firstArtisan = strpos($script, 'php artisan optimize:clear', $selection ?: 0);

        $this->assertNotFalse($selection);
        $this->assertNotFalse($permissionApply);
        $this->assertNotFalse($readability);
        $this->assertNotFalse($firstArtisan);
        $this->assertLessThan($permissionApply, $selection);
        $this->assertLessThan($readability, $permissionApply);
        $this->assertLessThan($firstArtisan, $readability);
    }

    public function test_environment_sync_is_followed_by_permission_and_web_runtime_revalidation(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $sync = strpos($script, 'php artisan engage:environment:sync --write-missing');
        $permissionApply = strpos($script, 'apply_local_env_permissions', $sync ?: 0);
        $readability = strpos($script, 'assert_local_env_readability', $permissionApply ?: 0);
        $webRuntime = strpos($script, 'assert_web_runtime', $readability ?: 0);

        $this->assertNotFalse($sync);
        $this->assertNotFalse($permissionApply);
        $this->assertNotFalse($readability);
        $this->assertNotFalse($webRuntime);
        $this->assertLessThan($permissionApply, $sync);
        $this->assertLessThan($readability, $permissionApply);
        $this->assertLessThan($webRuntime, $readability);
    }

    public function test_local_bootstrap_validates_the_selected_client_under_the_web_runtime_identity(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $this->assertStringContainsString(
            'sudo -u "$WEB_USER" php -r',
            $script,
        );
        $this->assertStringContainsString(
            '(new App\\Support\\Clients\\ClientEnvironmentLoader())->load(__DIR__);',
            $script,
        );
        $this->assertStringContainsString(
            'Local PHP-FPM/web runtime database is wrong:',
            $script,
        );
        $this->assertStringContainsString(
            'Local PHP-FPM/web runtime database user is wrong:',
            $script,
        );
        $this->assertStringContainsString(
            'Refusing Laravel fallback database [laravel].',
            $script,
        );
        $this->assertStringContainsString(
            'Refusing Laravel fallback user [root].',
            $script,
        );
    }

    public function test_client_specific_caches_are_invalidated_before_first_artisan_boot_after_selection(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $selection = strpos($script, 'env_set "$ROOT_ENV" CLIENT_KEY "$CLIENT_KEY"');
        $cacheInvalidation = strpos($script, 'invalidate_client_runtime_caches', $selection ?: 0);
        $firstArtisan = strpos($script, 'php artisan optimize:clear', $selection ?: 0);

        $this->assertNotFalse($selection);
        $this->assertNotFalse($cacheInvalidation);
        $this->assertNotFalse($firstArtisan);
        $this->assertLessThan($firstArtisan, $cacheInvalidation);
    }

    public function test_failed_selection_restores_previous_client_and_local_env_permissions(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $restore = strpos($script, 'restore_previous_client_on_failure()');
        $selectionRestore = strpos(
            $script,
            'env_set "$ROOT_ENV" CLIENT_KEY "$PREVIOUS_CLIENT_KEY"',
            $restore ?: 0,
        );
        $permissionApply = strpos(
            $script,
            'apply_local_env_permissions',
            $selectionRestore ?: 0,
        );

        $this->assertStringContainsString(
            'trap restore_previous_client_on_failure EXIT',
            $script,
        );
        $this->assertStringContainsString(
            'env_remove "$ROOT_ENV" CLIENT_KEY',
            $script,
        );
        $this->assertNotFalse($restore);
        $this->assertNotFalse($selectionRestore);
        $this->assertNotFalse($permissionApply);
        $this->assertLessThan($permissionApply, $selectionRestore);
    }

    public function test_local_bootstrap_uses_safe_local_provider_decisions_and_normal_install_path(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $this->assertStringContainsString(
            'env_set_if_blank "$CLIENT_ENV" EMAIL_PROVIDER resend',
            $script,
        );
        $this->assertStringContainsString(
            'env_set_if_blank "$CLIENT_ENV" SMS_ENABLED false',
            $script,
        );
        $this->assertStringContainsString(
            'env_set_if_blank "$CLIENT_ENV" FORMS_EXTERNAL_INTAKE_ENABLED false',
            $script,
        );
        $this->assertStringContainsString(
            'php artisan engage:environment:sync --write-missing',
            $script,
        );
        $this->assertStringContainsString(
            'php artisan engage:install',
            $script,
        );
        $this->assertStringContainsString(
            'php artisan engage:deployment-plan',
            $script,
        );
        $this->assertStringContainsString(
            'php artisan modules:status',
            $script,
        );
    }

    public function test_create_client_invokes_the_reusable_local_bootstrap_after_source_is_published(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/create-client.sh'),
        );

        $publish = strpos(
            $script,
            'mv "$TEMP_CLIENT_DIR" "$CLIENT_DIR"',
        );
        $bootstrap = strpos(
            $script,
            '"$LOCAL_BOOTSTRAP" "$CLIENT_KEY"',
        );

        $this->assertNotFalse($publish);
        $this->assertNotFalse($bootstrap);
        $this->assertLessThan($bootstrap, $publish);

        $this->assertStringContainsString(
            'Client source and GitHub repository remain intact.',
            $script,
        );
        $this->assertStringContainsString(
            './scripts/bootstrap-client-local.sh $CLIENT_KEY',
            $script,
        );
    }
}
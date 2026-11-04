<?php

namespace Tests\Feature\Clients;

use Illuminate\Filesystem\Filesystem;
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

    public function test_local_bootstrap_restricts_env_files_and_preserves_web_readability(): void
    {
        $this->withPermissionsFixture(function (string $directory): void {
            $clientDirectory = $directory.'/client';
            $this->assertTrue(mkdir($clientDirectory, 0750));

            $rootEnv = $directory.'/.env';
            $clientEnv = $clientDirectory.'/.env';
            $rootContent = "APP_ENV=local\n";
            $clientContent = "ROOT_DOMAIN=engagecore.test\n";

            $this->assertNotFalse(file_put_contents($rootEnv, $rootContent));
            $this->assertNotFalse(file_put_contents($clientEnv, $clientContent));
            $this->assertTrue(chmod($rootEnv, 0664));
            $this->assertTrue(chmod($clientEnv, 0664));

            $this->runPermissionsHelper('prepare-env', $rootEnv, $clientEnv);

            foreach ([$rootEnv => $rootContent, $clientEnv => $clientContent] as $file => $content) {
                clearstatcache(true, $file);

                $this->assertSame(0640, fileperms($file) & 0777);
                $this->assertSame(posix_geteuid(), fileowner($file));
                $this->assertSame(posix_getegid(), filegroup($file));
                $this->assertSame($content, file_get_contents($file));
            }
        });
    }

    public function test_local_bootstrap_normalizes_selected_client_config_for_the_web_runtime(): void
    {
        $this->withPermissionsFixture(function (string $directory): void {
            $clientDirectory = $directory.'/client';
            $configDirectory = $clientDirectory.'/config';
            $nestedDirectory = $configDirectory.'/nested';

            $this->assertTrue(mkdir($nestedDirectory, 0755, true));

            $configFiles = [
                $configDirectory.'/client.php',
                $configDirectory.'/modules.php',
                $nestedDirectory.'/settings.php',
            ];

            foreach ($configFiles as $file) {
                $this->assertNotFalse(file_put_contents($file, "<?php return [];\n"));
                $this->assertTrue(chmod($file, 0644));
            }

            $outsideConfig = $clientDirectory.'/unmanaged.txt';
            $this->assertNotFalse(file_put_contents($outsideConfig, "untouched\n"));
            $this->assertTrue(chmod($outsideConfig, 0664));

            $this->runPermissionsHelper('prepare-config', $clientDirectory);

            foreach ([$configDirectory, $nestedDirectory] as $directoryPath) {
                clearstatcache(true, $directoryPath);

                $this->assertSame(02750, fileperms($directoryPath) & 07777);
                $this->assertSame(posix_geteuid(), fileowner($directoryPath));
                $this->assertSame(posix_getegid(), filegroup($directoryPath));
            }

            foreach ($configFiles as $file) {
                clearstatcache(true, $file);

                $this->assertSame(0640, fileperms($file) & 0777);
                $this->assertSame(posix_geteuid(), fileowner($file));
                $this->assertSame(posix_getegid(), filegroup($file));
                $this->assertSame("<?php return [];\n", file_get_contents($file));
            }

            clearstatcache(true, $outsideConfig);
            $this->assertSame(0664, fileperms($outsideConfig) & 0777);
            $this->assertSame("untouched\n", file_get_contents($outsideConfig));
        });
    }
    public function test_local_bootstrap_requires_a_full_selected_client_boot_under_the_web_user_before_install(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/bootstrap-client-local.sh'),
        );

        $selection = strpos($script, 'env_set "$ROOT_ENV" CLIENT_KEY "$CLIENT_KEY"');
        $webBoot = strpos($script, 'assert_web_application_boot', $selection ?: 0);
        $install = strpos($script, 'php artisan engage:install', $selection ?: 0);

        $this->assertStringContainsString(
            'sudo -u "$WEB_USER" php "$ROOT_DIR/artisan" about --no-ansi',
            $script,
        );
        $this->assertNotFalse($selection);
        $this->assertNotFalse($webBoot);
        $this->assertNotFalse($install);
        $this->assertLessThan($install, $webBoot);
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

    /**
     * Exercise the real permission policy against disposable local paths only.
     * No client selection, MySQL provisioning, or privileged bootstrap runs.
     */
    private function withPermissionsFixture(callable $callback): void
    {
        $directory = sys_get_temp_dir().'/engage-bootstrap-permissions-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0750));

        try {
            $callback($directory);
        } finally {
            (new Filesystem())->deleteDirectory($directory);
        }
    }

    private function runPermissionsHelper(string $operation, string ...$paths): void
    {
        $user = posix_getpwuid(posix_geteuid());
        $group = posix_getgrgid(posix_getegid());

        $this->assertIsArray($user);
        $this->assertIsArray($group);
        $this->assertNotEmpty($user['name'] ?? null);
        $this->assertNotEmpty($group['name'] ?? null);

        // Using the test runner's own identity/group lets the policy be checked
        // without sudo or access to the real machine's PHP-FPM account.
        $process = new Process(
            [
                'python3',
                base_path('scripts/operations/lib/client_file_permissions.py'),
                $operation,
                ...$paths,
            ],
            base_path(),
            [
                'ENGAGE_CORE_WEB_USER' => $user['name'],
                'ENGAGE_CORE_WEB_GROUP' => $group['name'],
            ],
        );

        $process->mustRun();
    }
}
<?php

namespace Tests\Feature\Clients;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class AddClientModulesScriptTest extends TestCase
{
    public function test_add_client_modules_script_parses_as_valid_bash(): void
    {
        $process = new Process([
            'bash',
            '-n',
            base_path('scripts/add-client-modules.sh'),
        ]);

        $process->mustRun();

        $this->assertSame('', $process->getErrorOutput());
    }

    public function test_add_client_modules_preserves_php_fpm_readable_modules_config(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/add-client-modules.sh'),
        );

        $this->assertStringContainsString(
            'WEB_GROUP="${ENGAGE_CORE_WEB_GROUP:-www-data}"',
            $script,
        );
        $this->assertStringContainsString(
            'normalize_modules_source_permissions()',
            $script,
        );
        $this->assertStringContainsString(
            'chmod 2750 "$config_dir"',
            $script,
        );
        $this->assertStringContainsString(
            'chmod 0640 "$MODULES_FILE"',
            $script,
        );
    }

    public function test_atomic_modules_replacement_is_web_readable_before_rename_and_rechecked_afterward(): void
    {
        $script = (string) file_get_contents(
            base_path('scripts/add-client-modules.sh'),
        );

        $temporaryPermission = strpos($script, 'chmod($temporaryFile, 0640)');
        $rename = strpos($script, 'rename($temporaryFile, $modulesFile)');
        $changedGuard = strpos($script, 'if [[ "$CHANGED" != "1" ]]');
        $finalNormalization = strpos(
            $script,
            'normalize_modules_source_permissions',
            $changedGuard ?: 0,
        );
        $syntaxCheck = strpos($script, 'php -l "$MODULES_FILE"', $changedGuard ?: 0);

        $this->assertNotFalse($temporaryPermission);
        $this->assertNotFalse($rename);
        $this->assertNotFalse($changedGuard);
        $this->assertNotFalse($finalNormalization);
        $this->assertNotFalse($syntaxCheck);
        $this->assertLessThan($rename, $temporaryPermission);
        $this->assertLessThan($syntaxCheck, $finalNormalization);
    }
}
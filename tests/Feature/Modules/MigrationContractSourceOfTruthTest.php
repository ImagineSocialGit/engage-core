<?php

namespace Tests\Feature\Modules;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrationContractSourceOfTruthTest extends TestCase
{
    public function test_migration_configuration_declares_paths_without_duplicating_discovered_contract_data(): void
    {
        $forbiddenFields = [];

        $this->collectForbiddenFields(
            config('module_migrations', []),
            'module_migrations',
            $forbiddenFields,
        );

        $this->assertSame(
            [],
            $forbiddenFields,
            'Migration configuration must not duplicate discovered schema versions or migration manifests: '.
            implode(', ', $forbiddenFields),
        );
    }

    public function test_tests_do_not_hard_code_evolving_migration_or_schema_contract_versions(): void
    {
        $patterns = [
            'configured migration schema version' => '/config\(\s*[\'"]module_migrations\.[^\'"]*\.schema_version[\'"]/s',
            'configured migration manifest' => '/config\(\s*[\'"]module_migrations\.[^\'"]*\.migrations[\'"]/s',
            'hard-coded migration schema version' => '/\$this->assertSame\(\s*\d+\s*,\s*[^;]*->schemaVersion\s*[,)]/s',
            'hard-coded migration-file count' => '/\$this->assertCount\(\s*\d+\s*,\s*[^;]*->migrationFiles\s*\)/s',
            'hard-coded expected migration count' => '/\$this->assertSame\(\s*\d+\s*,\s*\$status->expectedMigrationCount\s*\)/s',
            'hard-coded ran migration count' => '/\$this->assertSame\(\s*\d+\s*,\s*\$status->ranMigrationCount\s*\)/s',
            'hard-coded serialized schema version' => '/\$this->assertSame\(\s*\d+\s*,\s*\$[A-Za-z_][A-Za-z0-9_]*\[[^;]*[\'"]schema_version[\'"]\][^;]*\)/s',
            'hard-coded Project State config version' => '/\$this->assertSame\(\s*\d+\s*,\s*(?:\(int\)\s*)?config\(\s*[\'"]project_state\.[^\'"]*version[\'"]/s',
            'hard-coded Project State section version' => '/\$this->assertSame\(\s*\d+\s*,\s*\$document\[[^;]*[\'"]sections[\'"][^;]*[\'"]version[\'"]\][^;]*\)/s',
        ];

        $violations = [];
        $thisPath = realpath(__FILE__);

        foreach (File::allFiles(base_path('tests')) as $file) {
            if (realpath($file->getPathname()) === $thisPath) {
                continue;
            }

            $contents = File::get($file->getPathname());

            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $contents) !== 1) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s: %s',
                    str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()),
                    $label,
                );
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Evolving migration and schema contract versions must come from their authoritative runtime source.\n".
            implode("\n", $violations),
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param array<int, string> $forbiddenFields
     */
    private function collectForbiddenFields(
        array $values,
        string $path,
        array &$forbiddenFields,
    ): void {
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $fieldPath = $path.'.'.$key;

            if (in_array($key, ['schema_version', 'migrations'], true)) {
                $forbiddenFields[] = $fieldPath;
            }

            if (is_array($value)) {
                $this->collectForbiddenFields($value, $fieldPath, $forbiddenFields);
            }
        }
    }
}
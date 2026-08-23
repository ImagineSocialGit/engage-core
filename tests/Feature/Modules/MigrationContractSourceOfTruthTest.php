<?php

namespace Tests\Feature\Modules;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MigrationContractSourceOfTruthTest extends TestCase
{
    public function test_current_migration_contract_assertions_do_not_duplicate_manifest_numbers(): void
    {
        $patterns = [
            'hard-coded schema version' => '/\$this->assertSame\(\s*\d+\s*,\s*[^;]*->schemaVersion\s*[,)]/s',
            'hard-coded migration-file count' => '/\$this->assertCount\(\s*\d+\s*,\s*[^;]*->migrationFiles\s*\)/s',
            'hard-coded expected migration count' => '/\$this->assertSame\(\s*\d+\s*,\s*\$status->expectedMigrationCount\s*\)/s',
            'hard-coded ran migration count' => '/\$this->assertSame\(\s*\d+\s*,\s*\$status->ranMigrationCount\s*\)/s',
        ];

        $violations = [];

        foreach (File::allFiles(base_path('tests/Feature')) as $file) {
            if ($file->getFilename() === basename(__FILE__)) {
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

        $this->assertEquals(
            [],
            $violations,
            "Current migration-contract numbers must come from config/module_migrations.php or the registry itself.\n".
            implode("\n", $violations),
        );
    }
}
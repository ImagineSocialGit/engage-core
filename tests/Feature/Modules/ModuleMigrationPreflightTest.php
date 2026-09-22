<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleInstallationRepository;
use App\Support\Modules\Migrations\ModuleMigrationPlanner;
use App\Support\Modules\Migrations\ModuleMigrationPreflightInspector;
use App\Support\Modules\Migrations\ModuleMigrationPreflightResult;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ModuleMigrationPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_preflight_is_read_only_and_new_pending_migration_is_safe_after_a_baseline_exists(): void
    {
        app(ModuleInstallationRepository::class)->markInstalled('core');

        $path = $this->temporaryMigrationPath(
            '2099_01_01_000010_test_safe_pending_module_migration.php',
        );
        File::put($path, $this->migrationSource('safe pending'));

        try {
            $beforeMigrations = DB::table('migrations')->count();
            $beforeInstallation = ModuleInstallation::query()->findOrFail('core');
            $beforeUpdatedAt = $beforeInstallation->updated_at;
            $beforeChecksums = $beforeInstallation->migration_checksums;

            $result = $this->preflight('core');

            $this->assertTrue($result->safe());
            $this->assertSame([], $result->blockers);
            $this->assertSame($beforeMigrations, DB::table('migrations')->count());

            $afterInstallation = ModuleInstallation::query()->findOrFail('core');
            $this->assertTrue($afterInstallation->updated_at?->equalTo($beforeUpdatedAt));
            $this->assertSame($beforeChecksums, $afterInstallation->migration_checksums);
        } finally {
            File::delete($path);
        }
    }

    public function test_changed_applied_migration_blocks_execution_and_preflight_command_without_mutating_ledgers(): void
    {
        $filename = '2099_01_01_000011_test_changed_applied_module_migration.php';
        $path = $this->temporaryMigrationPath($filename);
        File::put($path, $this->migrationSource('original'));
        $this->recordMigration($filename);
        app(ModuleInstallationRepository::class)->markInstalled('core');

        try {
            $installation = ModuleInstallation::query()->findOrFail('core');
            $updatedAt = $installation->updated_at;
            $checksums = $installation->migration_checksums;
            $migrationCount = DB::table('migrations')->count();

            File::put($path, $this->migrationSource('changed'));

            $result = $this->preflight('core');

            $this->assertFalse($result->safe());
            $this->assertNotSame([], $result->blockers);
            $this->assertSame(1, Artisan::call('modules:preflight', [
                'module' => 'core',
            ]));
            $this->assertSame(1, Artisan::call('modules:migrate', [
                'module' => 'core',
            ]));
            $this->assertSame($migrationCount, DB::table('migrations')->count());

            $after = ModuleInstallation::query()->findOrFail('core');
            $this->assertTrue($after->updated_at?->equalTo($updatedAt));
            $this->assertSame($checksums, $after->migration_checksums);
        } finally {
            $this->deleteMigrationRecord($filename);
            File::delete($path);
        }
    }

    public function test_missing_previously_accepted_migration_blocks(): void
    {
        $filename = '2099_01_01_000012_test_missing_accepted_module_migration.php';
        $path = $this->temporaryMigrationPath($filename);
        File::put($path, $this->migrationSource('missing'));
        $this->recordMigration($filename);
        app(ModuleInstallationRepository::class)->markInstalled('core');
        File::delete($path);

        try {
            $this->assertFalse($this->preflight('core')->safe());
        } finally {
            $this->deleteMigrationRecord($filename);
        }
    }

    public function test_applied_migration_absent_from_baseline_blocks_installed_scope(): void
    {
        app(ModuleInstallationRepository::class)->markInstalled('core');

        $filename = '2099_01_01_000013_test_untracked_applied_module_migration.php';
        $path = $this->temporaryMigrationPath($filename);
        File::put($path, $this->migrationSource('untracked'));
        $this->recordMigration($filename);

        try {
            $this->assertFalse($this->preflight('core')->safe());
        } finally {
            $this->deleteMigrationRecord($filename);
            File::delete($path);
        }
    }

    public function test_installed_scope_missing_laravel_history_for_an_accepted_migration_blocks(): void
    {
        app(ModuleInstallationRepository::class)->markInstalled('core');
        $scope = app(ModuleMigrationRegistry::class)->requireModule('core');
        $filename = $scope->migrationFiles[0];

        DB::table('migrations')
            ->where('migration', pathinfo($filename, PATHINFO_FILENAME))
            ->delete();

        $this->assertFalse($this->preflight('core')->safe());
    }

    public function test_malformed_stored_checksum_baseline_blocks(): void
    {
        app(ModuleInstallationRepository::class)->markInstalled('core');

        DB::table('module_installations')
            ->where('module_key', 'core')
            ->update([
                'migration_checksums' => json_encode([
                    'invalid.php' => 'not-a-sha256-hash',
                ], JSON_THROW_ON_ERROR),
            ]);

        $result = $this->preflight('core');

        $this->assertFalse($result->safe());
        $this->assertNotSame([], $result->blockers);
    }

    public function test_missing_baseline_is_safe_only_when_installed_scope_has_no_pending_migrations(): void
    {
        app(ModuleInstallationRepository::class)->markInstalled('core');
        ModuleInstallation::query()->whereKey('core')->update([
            'migration_checksums' => null,
        ]);

        $current = $this->preflight('core');

        $this->assertTrue($current->safe());
        $this->assertNotSame([], $current->warnings);

        $path = $this->temporaryMigrationPath(
            '2099_01_01_000014_test_pending_without_baseline.php',
        );
        File::put($path, $this->migrationSource('pending without baseline'));

        try {
            $this->assertFalse($this->preflight('core')->safe());
        } finally {
            File::delete($path);
        }
    }

    public function test_preflight_command_can_limit_to_one_dependency_closure_without_mutating_state(): void
    {
        $installationCount = ModuleInstallation::query()->count();
        $migrationCount = DB::table('migrations')->count();

        $this->assertSame(0, Artisan::call('modules:preflight', [
            'module' => 'scheduling',
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString('core', $output);
        $this->assertStringContainsString('scheduling', $output);
        $this->assertStringNotContainsString('location', $output);
        $this->assertSame($installationCount, ModuleInstallation::query()->count());
        $this->assertSame($migrationCount, DB::table('migrations')->count());
    }

    private function preflight(string $moduleKey): ModuleMigrationPreflightResult
    {
        return app(ModuleMigrationPreflightInspector::class)->inspect(
            app(ModuleMigrationPlanner::class)->forModule($moduleKey),
        );
    }

    private function temporaryMigrationPath(string $filename): string
    {
        $scope = app(ModuleMigrationRegistry::class)->requireModule('core');

        return base_path($scope->path.'/'.$filename);
    }

    private function recordMigration(string $filename): void
    {
        DB::table('migrations')->insert([
            'migration' => pathinfo($filename, PATHINFO_FILENAME),
            'batch' => (int) DB::table('migrations')->max('batch') + 1,
        ]);
    }

    private function deleteMigrationRecord(string $filename): void
    {
        DB::table('migrations')
            ->where('migration', pathinfo($filename, PATHINFO_FILENAME))
            ->delete();
    }

    private function migrationSource(string $marker): string
    {
        return <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;

// {$marker}
return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
PHP;
    }
}
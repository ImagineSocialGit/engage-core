<?php

namespace Tests\Feature\SetupValidation;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleInstallationRepository;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\SetupValidation\Contributors\ModuleMigrationsSetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ModuleMigrationsSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_scheduling_requires_current_tracked_core_and_scheduling_without_location(): void
    {
        config()->set('modules.enabled', ['scheduling']);

        $findings = $this->findings();
        $codes = array_column($findings, 'code');
        $modules = array_column($findings, 'module');

        $this->assertContains('app.modules.migrations.untracked', $codes);
        $this->assertContains('core', $modules);
        $this->assertContains('scheduling', $modules);
        $this->assertNotContains('location', $modules);
        $this->assertNotContains('reporting', $modules);
    }

    public function test_reconciled_current_scheduling_closure_has_no_migration_findings(): void
    {
        config()->set('modules.enabled', ['scheduling']);

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        $this->assertEquals([], $this->findings());
    }

    public function test_interrupted_and_failed_installation_states_are_reported(): void
    {
        config()->set('modules.enabled', ['scheduling']);

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        $repository = app(ModuleInstallationRepository::class);
        $repository->begin('scheduling');

        $this->assertContains(
            'app.modules.migrations.installing',
            array_column($this->findings(), 'code'),
        );

        $repository->markFailed('scheduling');

        $this->assertContains(
            'app.modules.migrations.failed',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_installed_contract_metadata_drift_is_reported_without_integrity_noise(): void
    {
        config()->set('modules.enabled', ['scheduling']);

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        ModuleInstallation::query()
            ->whereKey('scheduling')
            ->update([
                'schema_version' => 999,
                'manifest_hash' => str_repeat('0', 64),
            ]);

        $scheduling = collect($this->findings())
            ->where('module', 'scheduling')
            ->values();

        $this->assertContains(
            'app.modules.migrations.contract_drift',
            $scheduling->pluck('code')->all(),
        );
        $this->assertNotContains(
            'app.modules.migrations.applied_file_changed',
            $scheduling->pluck('code')->all(),
        );
    }

    public function test_baseline_missing_is_warning_when_current_and_error_when_pending(): void
    {
        config()->set('modules.enabled', ['scheduling']);

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        ModuleInstallation::query()->whereKey('scheduling')->update([
            'migration_checksums' => null,
        ]);

        $finding = collect($this->findings())
            ->where('module', 'scheduling')
            ->firstWhere('code', 'app.modules.migrations.integrity_baseline_missing');

        $this->assertNotNull($finding);
        $this->assertSame(SetupValidationFinding::SEVERITY_WARNING, $finding['severity']);

        $scope = app(ModuleMigrationRegistry::class)->requireModule('scheduling');
        $filename = '2099_01_01_000030_test_setup_pending_without_baseline.php';
        $path = base_path($scope->path.'/'.$filename);
        File::put($path, $this->migrationSource('pending'));

        try {
            $finding = collect($this->findings())
                ->where('module', 'scheduling')
                ->firstWhere('code', 'app.modules.migrations.integrity_baseline_missing');

            $this->assertNotNull($finding);
            $this->assertSame(SetupValidationFinding::SEVERITY_ERROR, $finding['severity']);
        } finally {
            File::delete($path);
        }
    }

    public function test_integrity_drift_uses_specific_semantic_finding_codes(): void
    {
        config()->set('modules.enabled', ['scheduling']);
        $scope = app(ModuleMigrationRegistry::class)->requireModule('scheduling');
        $repository = app(ModuleInstallationRepository::class);

        $changedFilename = '2099_01_01_000031_test_setup_changed.php';
        $changedPath = base_path($scope->path.'/'.$changedFilename);
        File::put($changedPath, $this->migrationSource('original'));
        $this->recordMigration($changedFilename);
        $repository->markInstalled('scheduling');
        File::put($changedPath, $this->migrationSource('changed'));

        try {
            $codes = collect($this->findings())
                ->where('module', 'scheduling')
                ->pluck('code')
                ->all();

            $this->assertContains('app.modules.migrations.applied_file_changed', $codes);
            $this->assertNotContains('app.modules.migrations.contract_drift', $codes);
        } finally {
            $this->deleteMigrationRecord($changedFilename);
            File::delete($changedPath);
        }

        $missingFilename = '2099_01_01_000032_test_setup_missing.php';
        $missingPath = base_path($scope->path.'/'.$missingFilename);
        File::put($missingPath, $this->migrationSource('missing'));
        $this->recordMigration($missingFilename);
        $repository->markInstalled('scheduling');
        File::delete($missingPath);

        try {
            $codes = collect($this->findings())
                ->where('module', 'scheduling')
                ->pluck('code')
                ->all();

            $this->assertContains('app.modules.migrations.recorded_file_missing', $codes);
        } finally {
            $this->deleteMigrationRecord($missingFilename);
        }

        $repository->markInstalled('scheduling');
        $untrackedFilename = '2099_01_01_000033_test_setup_untracked.php';
        $untrackedPath = base_path($scope->path.'/'.$untrackedFilename);
        File::put($untrackedPath, $this->migrationSource('untracked'));
        $this->recordMigration($untrackedFilename);

        try {
            $codes = collect($this->findings())
                ->where('module', 'scheduling')
                ->pluck('code')
                ->all();

            $this->assertContains('app.modules.migrations.applied_file_untracked', $codes);
        } finally {
            $this->deleteMigrationRecord($untrackedFilename);
            File::delete($untrackedPath);
        }
    }

    public function test_reporting_now_requires_its_owned_schema_to_be_tracked(): void
    {
        config()->set('modules.enabled', ['reporting']);

        $modules = array_column($this->findings(), 'module');

        $this->assertContains('core', $modules);
        $this->assertContains('reporting', $modules);

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'reporting',
        ]));

        $this->assertEquals([], $this->findings());
    }

    public function test_schema_free_enabled_modules_do_not_create_false_migration_errors(): void
    {
        config()->set('modules.enabled', ['integrations']);

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'integrations',
        ]));

        $this->assertEquals([], $this->findings());
    }

    public function test_setup_validate_returns_failure_for_migration_errors_without_copy_assertions(): void
    {
        config()->set('modules.enabled', ['scheduling']);

        $this->app->instance(
            SetupValidationManager::class,
            new SetupValidationManager([
                app(ModuleMigrationsSetupValidationContributor::class),
            ]),
        );

        $this->assertSame(1, Artisan::call('setup:validate'));
        $this->assertContains(
            'app.modules.migrations.untracked',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_app_level_validation_registration_includes_migration_contributor(): void
    {
        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            iterator_to_array(
                $this->app->tagged('setup.validation_contributors'),
                false,
            ),
        );

        $this->assertContains(
            ModuleMigrationsSetupValidationContributor::class,
            $classes,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function findings(): array
    {
        return array_map(
            static fn ($finding): array => $finding->toArray(),
            iterator_to_array(
                app(ModuleMigrationsSetupValidationContributor::class)
                    ->findings(),
                false,
            ),
        );
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
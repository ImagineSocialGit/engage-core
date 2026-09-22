<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleInstallationRepository;
use App\Support\Modules\Migrations\ModuleMigrationPlanner;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\Migrations\ModuleMigrationStatus;
use App\Support\Modules\Migrations\ModuleMigrationStatusInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class ModuleMigrationPlanningAndStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_resolves_scheduling_to_core_and_scheduling_without_location(): void
    {
        $plan = app(ModuleMigrationPlanner::class)->forModule('scheduling');

        $this->assertEquals(['scheduling'], $plan->requestedModuleKeys);
        $this->assertEquals(['core', 'scheduling'], $plan->dependencyOrderedModuleKeys);
        $this->assertEquals(['core', 'scheduling'], $plan->migrationModuleKeys());
        $this->assertNotContains('location', $plan->dependencyOrderedModuleKeys);
    }

    public function test_planner_resolves_transitive_dependencies_and_omits_schema_free_modules(): void
    {
        $internalNotifications = app(ModuleMigrationPlanner::class)
            ->forModule('internal_notifications');

        $this->assertEquals([
            'core',
            'messaging',
            'internal_notifications',
        ], $internalNotifications->dependencyOrderedModuleKeys);
        $this->assertEquals([
            'core',
            'messaging',
            'internal_notifications',
        ], $internalNotifications->migrationModuleKeys());

        $reporting = app(ModuleMigrationPlanner::class)->forModule('reporting');

        $this->assertEquals(['core', 'reporting'], $reporting->dependencyOrderedModuleKeys);
        $this->assertEquals(['core', 'reporting'], $reporting->migrationModuleKeys());

        $integrations = app(ModuleMigrationPlanner::class)->forModule('integrations');

        $this->assertEquals(['core', 'integrations'], $integrations->dependencyOrderedModuleKeys);
        $this->assertEquals(['core'], $integrations->migrationModuleKeys());
    }

    public function test_planner_rejects_unknown_modules_and_dependency_cycles(): void
    {
        try {
            app(ModuleMigrationPlanner::class)->forModule('unknown');
            $this->fail('Unknown modules must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Unknown module [unknown].', $exception->getMessage());
        }

        config()->set('modules.modules.core.depends_on', ['scheduling']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Module dependency cycle detected: [scheduling -> core -> scheduling].',
        );

        app(ModuleMigrationPlanner::class)->forModule('scheduling');
    }

    public function test_status_inspector_separates_migration_currency_ledger_contract_and_integrity(): void
    {
        $registry = app(ModuleMigrationRegistry::class);
        $scope = $registry->requireModule('scheduling');
        $inspector = app(ModuleMigrationStatusInspector::class);
        $status = $inspector->inspectModule('scheduling');

        $this->assertSame(ModuleMigrationStatus::MIGRATIONS_CURRENT, $status->migrationState);
        $this->assertSame(count($scope->migrationFiles), $status->expectedMigrationCount);
        $this->assertSame(count($scope->migrationFiles), $status->ranMigrationCount);
        $this->assertSame([], $status->pendingMigrationFiles);
        $this->assertSame(ModuleMigrationStatus::LEDGER_UNTRACKED, $status->ledgerStatus);
        $this->assertSame(ModuleMigrationStatus::CONTRACT_UNTRACKED, $status->contractState);
        $this->assertSame(ModuleMigrationStatus::INTEGRITY_UNTRACKED, $status->integrityState);
        $this->assertTrue($status->current());
        $this->assertFalse($status->ledgerCurrent());

        app(ModuleInstallationRepository::class)->markInstalled('scheduling');

        $tracked = $inspector->inspectModule('scheduling');

        $this->assertSame(ModuleInstallation::STATUS_INSTALLED, $tracked->ledgerStatus);
        $this->assertSame(ModuleMigrationStatus::CONTRACT_CURRENT, $tracked->contractState);
        $this->assertSame(ModuleMigrationStatus::INTEGRITY_CURRENT, $tracked->integrityState);
        $this->assertSame($scope->migrationChecksums, $tracked->recordedMigrationChecksums);
        $this->assertTrue($tracked->ledgerCurrent());

        ModuleInstallation::query()
            ->whereKey('scheduling')
            ->update([
                'schema_version' => 999,
                'manifest_hash' => str_repeat('0', 64),
            ]);

        $drifted = $inspector->inspectModule('scheduling');

        $this->assertSame(ModuleMigrationStatus::CONTRACT_DRIFT, $drifted->contractState);
        $this->assertSame(ModuleMigrationStatus::INTEGRITY_CURRENT, $drifted->integrityState);
        $this->assertFalse($drifted->ledgerCurrent());
    }

    public function test_modules_status_command_is_read_only_and_can_limit_to_a_dependency_closure(): void
    {
        $installationCountBefore = ModuleInstallation::query()->count();
        $migrationCountBefore = DB::table('migrations')->count();

        $exitCode = Artisan::call('modules:status', [
            'module' => 'scheduling',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('core', $output);
        $this->assertStringContainsString('scheduling', $output);
        $this->assertStringNotContainsString('location', $output);
        $this->assertSame($installationCountBefore, ModuleInstallation::query()->count());
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());

        $this->assertSame(1, Artisan::call('modules:status', [
            'module' => 'unknown',
        ]));
    }

    public function test_newly_discovered_migration_is_pending_with_current_integrity_then_runs_and_extends_baseline(): void
    {
        $registry = app(ModuleMigrationRegistry::class);
        $inspector = app(ModuleMigrationStatusInspector::class);
        $scope = $registry->requireModule('core');
        $filename = '2099_01_01_000020_test_module_inventory_discovery.php';
        $path = base_path($scope->path.'/'.$filename);

        $this->assertSame(0, Artisan::call('modules:reconcile', ['module' => 'core']));
        $baseline = ModuleInstallation::query()->findOrFail('core')->migration_checksums;
        $this->assertIsArray($baseline);

        File::put($path, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
PHP);

        try {
            $migrationCountBefore = DB::table('migrations')->count();
            $installationBefore = ModuleInstallation::query()->findOrFail('core');

            $this->assertSame(0, Artisan::call('modules:status', ['module' => 'core']));
            $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
            $this->assertSame(
                $installationBefore->manifest_hash,
                ModuleInstallation::query()->findOrFail('core')->manifest_hash,
            );

            $pending = $inspector->inspectModule('core');
            $this->assertFalse($pending->current());
            $this->assertContains($filename, $pending->pendingMigrationFiles);
            $this->assertSame(ModuleMigrationStatus::INTEGRITY_CURRENT, $pending->integrityState);
            $this->assertFalse($pending->ledgerCurrent());

            $this->assertSame(0, Artisan::call('modules:migrate', ['module' => 'core']));

            $current = $inspector->inspectModule('core');
            $this->assertTrue($current->current());
            $this->assertTrue($current->ledgerCurrent());
            $this->assertSame(ModuleMigrationStatus::INTEGRITY_CURRENT, $current->integrityState);
            $this->assertArrayHasKey(
                $filename,
                ModuleInstallation::query()->findOrFail('core')->migration_checksums,
            );
            $this->assertDatabaseHas('migrations', [
                'migration' => pathinfo($filename, PATHINFO_FILENAME),
            ]);
        } finally {
            DB::table('migrations')
                ->where('migration', pathinfo($filename, PATHINFO_FILENAME))
                ->delete();
            File::delete($path);
        }
    }
}
<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleInstallationRepository;
use App\Support\Modules\Migrations\ModuleMigrationPlanner;
use App\Support\Modules\Migrations\ModuleMigrationStatus;
use App\Support\Modules\Migrations\ModuleMigrationStatusInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ModuleMigrationPlanningAndStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_resolves_scheduling_to_core_and_scheduling_without_location(): void
    {
        $plan = app(ModuleMigrationPlanner::class)->forModule('scheduling');

        $this->assertEquals(['scheduling'], $plan->requestedModuleKeys);
        $this->assertEquals([
            'core',
            'scheduling',
        ], $plan->dependencyOrderedModuleKeys);
        $this->assertEquals([
            'core',
            'scheduling',
        ], $plan->migrationModuleKeys());
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

        $this->assertEquals([
            'core',
            'reporting',
        ], $reporting->dependencyOrderedModuleKeys);
        $this->assertEquals(['core'], $reporting->migrationModuleKeys());
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

    public function test_status_inspector_separates_migration_currency_from_ledger_tracking(): void
    {
        $inspector = app(ModuleMigrationStatusInspector::class);
        $status = $inspector->inspectModule('scheduling');

        $this->assertSame(ModuleMigrationStatus::MIGRATIONS_CURRENT, $status->migrationState);
        $this->assertSame(10, $status->expectedMigrationCount);
        $this->assertSame(10, $status->ranMigrationCount);
        $this->assertEquals([], $status->pendingMigrationFiles);
        $this->assertSame(ModuleMigrationStatus::LEDGER_UNTRACKED, $status->ledgerStatus);
        $this->assertSame(ModuleMigrationStatus::CONTRACT_UNTRACKED, $status->contractState);
        $this->assertTrue($status->current());
        $this->assertFalse($status->ledgerCurrent());

        app(ModuleInstallationRepository::class)->markInstalled('scheduling');

        $tracked = $inspector->inspectModule('scheduling');

        $this->assertSame(ModuleInstallation::STATUS_INSTALLED, $tracked->ledgerStatus);
        $this->assertSame(ModuleMigrationStatus::CONTRACT_CURRENT, $tracked->contractState);
        $this->assertTrue($tracked->ledgerCurrent());

        ModuleInstallation::query()
            ->whereKey('scheduling')
            ->update([
                'schema_version' => 999,
                'manifest_hash' => str_repeat('0', 64),
            ]);

        $drifted = $inspector->inspectModule('scheduling');

        $this->assertSame(ModuleInstallation::STATUS_INSTALLED, $drifted->ledgerStatus);
        $this->assertSame(ModuleMigrationStatus::CONTRACT_DRIFT, $drifted->contractState);
        $this->assertFalse($drifted->ledgerCurrent());
    }

    public function test_status_inspector_reports_pending_manifest_migrations(): void
    {
        DB::table('migrations')
            ->where(
                'migration',
                '2026_08_03_190000_create_scheduling_resource_occupancy_tables',
            )
            ->delete();

        $status = app(ModuleMigrationStatusInspector::class)
            ->inspectModule('scheduling');

        $this->assertSame(ModuleMigrationStatus::MIGRATIONS_PARTIAL, $status->migrationState);
        $this->assertSame(9, $status->ranMigrationCount);
        $this->assertEquals([
            '2026_08_03_190000_create_scheduling_resource_occupancy_tables.php',
        ], $status->pendingMigrationFiles);
        $this->assertFalse($status->current());
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
        $this->assertStringContainsString('current', $output);
        $this->assertStringContainsString('untracked', $output);
        $this->assertSame($installationCountBefore, ModuleInstallation::query()->count());
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());

        $this->assertSame(1, Artisan::call('modules:status', [
            'module' => 'unknown',
        ]));
        $this->assertStringContainsString(
            'Unknown module [unknown].',
            Artisan::output(),
        );
    }
}
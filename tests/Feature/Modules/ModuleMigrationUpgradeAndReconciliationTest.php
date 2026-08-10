<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModuleMigrationUpgradeAndReconciliationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            0,
            Artisan::call('migrate:fresh', [
                '--force' => true,
            ]),
            Artisan::output(),
        );
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_targeted_reconciliation_adopts_current_scheduling_closure_without_location(): void
    {
        CarbonImmutable::setTestNow('2026-08-06 15:00:00 UTC');

        $migrationCountBefore = DB::table('migrations')->count();

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString(
            'Resolved modules: core, scheduling',
            $output,
        );
        $this->assertStringNotContainsString('location', $output);
        $this->assertStringContainsString('reconciled', $output);
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'core',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'scheduling',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseMissing('module_installations', [
            'module_key' => 'location',
        ]);

        $installedAt = ModuleInstallation::query()
            ->findOrFail('scheduling')
            ->installed_at;
        $lastMigratedAt = ModuleInstallation::query()
            ->findOrFail('scheduling')
            ->last_migrated_at;

        CarbonImmutable::setTestNow('2026-08-06 16:00:00 UTC');

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        $repeated = ModuleInstallation::query()->findOrFail('scheduling');

        $this->assertStringContainsString('current', Artisan::output());
        $this->assertTrue($repeated->installed_at?->equalTo($installedAt));
        $this->assertTrue($repeated->last_migrated_at?->equalTo($lastMigratedAt));
    }

    public function test_targeted_reconciliation_rejects_partial_closure_before_writing_any_ledger_rows(): void
    {
        DB::table('migrations')
            ->where(
                'migration',
                '2026_08_03_190000_create_scheduling_resource_occupancy_tables',
            )
            ->delete();

        $this->assertSame(1, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        $this->assertStringContainsString(
            'Module migration scope [scheduling] cannot be reconciled because migrations are [partial].',
            Artisan::output(),
        );
        $this->assertSame(0, ModuleInstallation::query()->count());
    }

    public function test_bulk_reconciliation_adopts_current_scopes_and_skips_absent_vertical_schema(): void
    {
        $this->assertSame(0, Artisan::call('modules:reconcile'));

        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'core',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'location',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'scheduling',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseMissing('module_installations', [
            'module_key' => 'mortgage',
        ]);
        $this->assertStringContainsString(
            'Module reconciliation completed.',
            Artisan::output(),
        );
    }

    public function test_module_migrate_rejects_untracked_scopes_without_installing_them(): void
    {
        $migrationCountBefore = DB::table('migrations')->count();

        $this->assertSame(1, Artisan::call('modules:migrate', [
            'module' => 'scheduling',
        ]));

        $this->assertStringContainsString(
            'Module migration scope [core] is not installed.',
            Artisan::output(),
        );
        $this->assertSame(0, ModuleInstallation::query()->count());
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
    }

    public function test_targeted_module_migrate_runs_only_installed_scheduling_closure(): void
    {
        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        $this->removeSchedulingResourceMigration();

        DB::table('migrations')
            ->where(
                'migration',
                '2026_04_15_195859_create_location_area_assignments_table',
            )
            ->delete();

        $this->assertSame(0, Artisan::call('modules:migrate', [
            'module' => 'scheduling',
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString(
            'Resolved modules: core, scheduling',
            $output,
        );
        $this->assertStringNotContainsString('location', $output);
        $this->assertStringContainsString('migrated', $output);
        $this->assertTrue(Schema::hasTable('scheduling_resources'));
        $this->assertTrue(Schema::hasTable('scheduling_resource_occupancies'));
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_03_190000_create_scheduling_resource_occupancy_tables',
        ]);
        $this->assertDatabaseMissing('migrations', [
            'migration' => '2026_04_15_195859_create_location_area_assignments_table',
        ]);
        $this->assertDatabaseMissing('module_installations', [
            'module_key' => 'location',
        ]);
    }

    public function test_bulk_module_migrate_runs_only_ledger_installed_scopes(): void
    {
        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        $this->removeSchedulingResourceMigration();

        DB::table('migrations')
            ->where(
                'migration',
                '2026_04_15_195859_create_location_area_assignments_table',
            )
            ->delete();

        $this->assertSame(0, Artisan::call('modules:migrate'));

        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_03_190000_create_scheduling_resource_occupancy_tables',
        ]);
        $this->assertDatabaseMissing('migrations', [
            'migration' => '2026_04_15_195859_create_location_area_assignments_table',
        ]);
        $this->assertDatabaseMissing('module_installations', [
            'module_key' => 'location',
        ]);
    }

    public function test_module_migrate_refreshes_drifted_contract_without_replaying_current_migrations(): void
    {
        CarbonImmutable::setTestNow('2026-08-06 15:00:00 UTC');

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        $installation = ModuleInstallation::query()->findOrFail('scheduling');
        $installedAt = $installation->installed_at;
        $migrationCountBefore = DB::table('migrations')->count();

        $installation->forceFill([
            'schema_version' => 999,
            'manifest_hash' => str_repeat('0', 64),
        ])->save();

        CarbonImmutable::setTestNow('2026-08-06 16:00:00 UTC');

        $this->assertSame(0, Artisan::call('modules:migrate', [
            'module' => 'scheduling',
        ]));

        $updated = ModuleInstallation::query()->findOrFail('scheduling');
        $scope = app(ModuleMigrationRegistry::class)->requireModule('scheduling');

        $this->assertStringContainsString('updated', Artisan::output());
        $this->assertTrue($updated->installed_at?->equalTo($installedAt));
        $this->assertSame($scope->schemaVersion, $updated->schema_version);
        $this->assertSame(
            app(ModuleMigrationRegistry::class)->manifestHash($scope),
            $updated->manifest_hash,
        );
        $this->assertSame(
            '2026-08-06 16:00:00',
            $updated->last_migrated_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
    }

    private function removeSchedulingResourceMigration(): void
    {
        DB::table('migrations')
            ->where(
                'migration',
                '2026_08_03_190000_create_scheduling_resource_occupancy_tables',
            )
            ->delete();

        Schema::dropIfExists('scheduling_resource_occupancies');
        Schema::dropIfExists('bookable_service_resource_requirements');
        Schema::dropIfExists('scheduling_host_resources');
        Schema::dropIfExists('scheduling_resources');
    }
}
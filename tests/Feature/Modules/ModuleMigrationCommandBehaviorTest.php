<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModuleMigrationCommandBehaviorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_targeted_reconciliation_adopts_current_scheduling_closure_without_location_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-08-06 15:00:00 UTC');

        $migrationCountBefore = DB::table('migrations')->count();

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

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
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());

        $installedAt = ModuleInstallation::query()->findOrFail('scheduling')->installed_at;
        $lastMigratedAt = ModuleInstallation::query()->findOrFail('scheduling')->last_migrated_at;

        CarbonImmutable::setTestNow('2026-08-06 16:00:00 UTC');

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        $repeated = ModuleInstallation::query()->findOrFail('scheduling');

        $this->assertTrue($repeated->installed_at?->equalTo($installedAt));
        $this->assertTrue($repeated->last_migrated_at?->equalTo($lastMigratedAt));
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
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
    }

    public function test_module_migrate_rejects_untracked_scopes_without_installing_them(): void
    {
        $migrationCountBefore = DB::table('migrations')->count();

        $this->assertSame(1, Artisan::call('modules:migrate', [
            'module' => 'scheduling',
        ]));

        $this->assertSame(0, ModuleInstallation::query()->count());
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
    }

    public function test_module_migrate_refreshes_contract_metadata_without_replaying_current_migrations_or_changing_checksums(): void
    {
        CarbonImmutable::setTestNow('2026-08-06 15:00:00 UTC');

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        $installation = ModuleInstallation::query()->findOrFail('scheduling');
        $installedAt = $installation->installed_at;
        $migrationCountBefore = DB::table('migrations')->count();
        $scope = app(ModuleMigrationRegistry::class)->requireModule('scheduling');

        $installation->forceFill([
            'schema_version' => 999,
            'manifest_hash' => str_repeat('0', 64),
        ])->save();

        CarbonImmutable::setTestNow('2026-08-06 16:00:00 UTC');

        $this->assertSame(0, Artisan::call('modules:migrate', [
            'module' => 'scheduling',
        ]));

        $updated = ModuleInstallation::query()->findOrFail('scheduling');

        $this->assertTrue($updated->installed_at?->equalTo($installedAt));
        $this->assertSame($scope->schemaVersion, $updated->schema_version);
        $this->assertSame(
            app(ModuleMigrationRegistry::class)->manifestHash($scope),
            $updated->manifest_hash,
        );
        $expectedChecksums = $scope->migrationChecksums;
        $actualChecksums = $updated->migration_checksums;
        ksort($expectedChecksums, SORT_STRING);
        ksort($actualChecksums, SORT_STRING);

        $this->assertSame($expectedChecksums, $actualChecksums);
        $this->assertSame(
            '2026-08-06 16:00:00',
            $updated->last_migrated_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
    }

    public function test_module_preflight_command_is_read_only(): void
    {
        $migrationCountBefore = DB::table('migrations')->count();
        $installationCountBefore = ModuleInstallation::query()->count();

        $this->assertSame(0, Artisan::call('modules:preflight', [
            'module' => 'scheduling',
        ]));

        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
        $this->assertSame($installationCountBefore, ModuleInstallation::query()->count());
    }
}
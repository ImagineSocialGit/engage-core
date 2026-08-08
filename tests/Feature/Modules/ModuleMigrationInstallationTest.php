<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleInstallationRepository;
use App\Support\Modules\Migrations\ModuleMigrationExecutor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModuleMigrationInstallationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * These tests intentionally mutate Laravel migration history and schema.
         * Rebuild before each case so isolation never depends on migration down()
         * methods or rollback ordering from unrelated modules.
         */
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

    public function test_install_tracks_current_scheduling_closure_without_location_and_is_idempotent(): void
    {
        CarbonImmutable::setTestNow('2026-08-06 12:00:00 UTC');

        $migrationCountBefore = DB::table('migrations')->count();

        $this->assertSame(0, Artisan::call('modules:install', [
            'module' => 'scheduling',
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString(
            'Resolved modules: core, scheduling',
            $output,
        );
        $this->assertStringNotContainsString('location', $output);
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

        CarbonImmutable::setTestNow('2026-08-06 13:00:00 UTC');

        $this->assertSame(0, Artisan::call('modules:install', [
            'module' => 'scheduling',
        ]));
        $this->assertStringContainsString('current', Artisan::output());

        $repeated = ModuleInstallation::query()->findOrFail('scheduling');

        $this->assertTrue($repeated->installed_at?->equalTo($installedAt));
        $this->assertTrue($repeated->last_migrated_at?->equalTo($lastMigratedAt));
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
    }

    public function test_install_runs_only_pending_paths_in_the_selected_dependency_closure(): void
    {
        DB::table('migrations')
            ->where(
                'migration',
                '2026_08_03_190000_create_scheduling_resource_occupancy_tables',
            )
            ->delete();

        DB::table('migrations')
            ->where(
                'migration',
                '2026_04_15_195859_create_location_area_assignments_table',
            )
            ->delete();

        Schema::dropIfExists('scheduling_resource_occupancies');
        Schema::dropIfExists('bookable_service_resource_requirements');
        Schema::dropIfExists('scheduling_host_resources');
        Schema::dropIfExists('scheduling_resources');

        $this->assertSame(0, Artisan::call('modules:install', [
            'module' => 'scheduling',
        ]));

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
        $this->assertStringContainsString('migrated', Artisan::output());
    }

    public function test_failed_scope_stops_the_plan_and_does_not_start_later_scopes(): void
    {
        config()->set(
            'module_migrations.modules.messaging.path',
            'database/migrations/modules/missing-messaging',
        );

        $migrationCountBefore = DB::table('migrations')->count();

        $this->assertSame(1, Artisan::call('modules:install', [
            'module' => 'internal_notifications',
        ]));

        $this->assertStringContainsString(
            'Module migration scope [messaging] failed',
            Artisan::output(),
        );
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'core',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'messaging',
            'status' => ModuleInstallation::STATUS_FAILED,
        ]);
        $this->assertDatabaseMissing('module_installations', [
            'module_key' => 'internal_notifications',
        ]);
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
    }

    public function test_interrupted_installing_state_is_resumed_to_installed(): void
    {
        app(ModuleInstallationRepository::class)->begin('scheduling');

        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'scheduling',
            'status' => ModuleInstallation::STATUS_INSTALLING,
        ]);

        $this->assertSame(0, Artisan::call('modules:install', [
            'module' => 'scheduling',
        ]));

        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'scheduling',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
    }

    public function test_global_lock_rejects_concurrent_module_installation(): void
    {
        $lock = Cache::lock(ModuleMigrationExecutor::LOCK_KEY, 300);

        $this->assertTrue($lock->get());

        try {
            $this->assertSame(1, Artisan::call('modules:install', [
                'module' => 'scheduling',
            ]));
            $this->assertStringContainsString(
                'Another module migration operation is already running.',
                Artisan::output(),
            );
            $this->assertSame(0, ModuleInstallation::query()->count());
        } finally {
            $lock->release();
        }
    }
}
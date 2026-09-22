<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleInstallationRepository;
use App\Support\Modules\Migrations\ModuleMigrationExecutor;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModuleMigrationInstallationTest extends TestCase
{
    use RefreshDatabase;

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
        $expectedChecksums = app(ModuleMigrationRegistry::class)
            ->requireModule('scheduling')
            ->migrationChecksums;
        $actualChecksums = ModuleInstallation::query()
            ->findOrFail('scheduling')
            ->migration_checksums;
        ksort($expectedChecksums, SORT_STRING);
        ksort($actualChecksums, SORT_STRING);

        $this->assertSame($expectedChecksums, $actualChecksums);
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());

        $installedAt = ModuleInstallation::query()->findOrFail('scheduling')->installed_at;
        $lastMigratedAt = ModuleInstallation::query()->findOrFail('scheduling')->last_migrated_at;

        CarbonImmutable::setTestNow('2026-08-06 13:00:00 UTC');

        $this->assertSame(0, Artisan::call('modules:install', [
            'module' => 'scheduling',
        ]));

        $repeated = ModuleInstallation::query()->findOrFail('scheduling');

        $this->assertTrue($repeated->installed_at?->equalTo($installedAt));
        $this->assertTrue($repeated->last_migrated_at?->equalTo($lastMigratedAt));
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
    }

    public function test_invalid_scope_configuration_stops_before_ledger_mutation(): void
    {
        config()->set(
            'module_migrations.modules.messaging.path',
            'database/migrations/modules/missing-messaging',
        );

        $migrationCountBefore = DB::table('migrations')->count();

        $this->assertSame(1, Artisan::call('modules:install', [
            'module' => 'internal_notifications',
        ]));

        $this->assertSame(0, ModuleInstallation::query()->count());
        $this->assertSame($migrationCountBefore, DB::table('migrations')->count());
    }

    public function test_interrupted_installing_state_preserves_baseline_and_is_resumed_to_installed(): void
    {
        $repository = app(ModuleInstallationRepository::class);
        $repository->markInstalled('scheduling');
        $accepted = ModuleInstallation::query()->findOrFail('scheduling')->migration_checksums;

        $repository->begin('scheduling');

        $installing = ModuleInstallation::query()->findOrFail('scheduling');
        $this->assertSame(ModuleInstallation::STATUS_INSTALLING, $installing->status);
        $this->assertSame($accepted, $installing->migration_checksums);

        $repository->markFailed('scheduling');
        $this->assertSame(
            $accepted,
            ModuleInstallation::query()->findOrFail('scheduling')->migration_checksums,
        );

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
            $this->assertSame(0, ModuleInstallation::query()->count());
        } finally {
            $lock->release();
        }
    }
}
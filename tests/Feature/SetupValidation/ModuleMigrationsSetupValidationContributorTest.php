<?php

namespace Tests\Feature\SetupValidation;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleInstallationRepository;
use App\Support\SetupValidation\Contributors\ModuleMigrationsSetupValidationContributor;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModuleMigrationsSetupValidationContributorTest extends TestCase
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

    public function test_partial_enabled_scope_reports_pending_migrations(): void
    {
        config()->set('modules.enabled', ['scheduling']);

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'scheduling',
        ]));

        DB::table('migrations')
            ->where(
                'migration',
                '2026_08_03_190000_create_scheduling_resource_occupancy_tables',
            )
            ->delete();

        $finding = collect($this->findings())->firstWhere(
            'code',
            'app.modules.migrations.partial',
        );

        $this->assertNotNull($finding);
        $this->assertSame('scheduling', $finding['module']);
        $this->assertContains(
            '2026_08_03_190000_create_scheduling_resource_occupancy_tables.php',
            $finding['context']['pending_migrations'],
        );
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

    public function test_installed_manifest_drift_is_reported(): void
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

        $finding = collect($this->findings())->firstWhere(
            'code',
            'app.modules.migrations.contract_drift',
        );

        $this->assertNotNull($finding);
        $this->assertSame('scheduling', $finding['module']);
        $this->assertSame(
            999,
            $finding['context']['recorded_schema_version'],
        );
        $this->assertSame(
            str_repeat('0', 64),
            $finding['meta']['recorded_manifest_hash'],
        );
    }

    public function test_schema_free_enabled_modules_do_not_create_false_migration_errors(): void
    {
        config()->set('modules.enabled', ['reporting']);

        $this->assertSame(0, Artisan::call('modules:reconcile', [
            'module' => 'reporting',
        ]));

        $this->assertEquals([], $this->findings());
    }

    public function test_missing_installation_ledger_is_reported_for_enabled_schema_scopes(): void
    {
        config()->set('modules.enabled', ['scheduling']);

        Schema::drop('module_installations');

        $findings = collect($this->findings())
            ->where('code', 'app.modules.migrations.ledger_missing')
            ->values()
            ->all();

        $this->assertEquals([
            'core',
            'scheduling',
        ], array_column($findings, 'module'));
    }

    public function test_setup_validate_outputs_migration_findings_and_returns_failure(): void
    {
        config()->set('modules.enabled', ['scheduling']);

        $this->app->instance(
            SetupValidationManager::class,
            new SetupValidationManager([
                app(ModuleMigrationsSetupValidationContributor::class),
            ]),
        );

        $this->assertSame(1, Artisan::call('setup:validate'));

        $output = Artisan::output();

        $this->assertStringContainsString(
            'app.modules.migrations.untracked',
            $output,
        );
        $this->assertStringContainsString(
            '[scheduling | modules.migrations | module_migrations.modules.scheduling]',
            $output,
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

    /**
     * @return array<int, array<string, mixed>>
     */
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
}
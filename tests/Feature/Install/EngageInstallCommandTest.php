<?php

namespace Tests\Feature\Install;

use App\Models\User;
use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\SetupValidation\Contributors\ModuleMigrationsSetupValidationContributor;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EngageInstallCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
    }

    public function test_install_builds_platform_and_selected_scheduling_schema_without_location_then_runs_final_stages(): void
    {
        config()->set('modules.enabled', ['scheduling']);
        $this->useMigrationOnlySetupValidation();

        $this->assertFalse(Schema::hasTable('migrations'));

        $this->assertSame(0, Artisan::call('engage:install', [
            '--modules' => 'scheduling',
            '--no-create-user' => true,
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString('[1/4] Platform migrations', $output);
        $this->assertStringContainsString('[2/4] Module installation', $output);
        $this->assertStringContainsString('[3/4] Preset synchronization', $output);
        $this->assertStringContainsString('[4/4] Setup validation', $output);
        $this->assertStringContainsString('Resolved modules: core, scheduling', $output);
        $this->assertStringContainsString('Preset package [basic] synced.', $output);
        $this->assertStringContainsString('Setup validation passed with no findings.', $output);
        $this->assertStringContainsString('CRM user creation skipped.', $output);
        $this->assertStringContainsString('Engage installation completed successfully.', $output);
        $this->assertStringNotContainsString('location', $output);

        $this->assertTrue(Schema::hasTable('module_installations'));
        $this->assertTrue(Schema::hasTable('contacts'));
        $this->assertTrue(Schema::hasTable('appointments'));
        $this->assertFalse(Schema::hasTable('locations'));
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
        $this->assertDatabaseMissing('migrations', [
            'migration' => '2026_04_15_195856_create_locations_table',
        ]);
    }

    public function test_install_without_modules_defaults_to_configured_enabled_schema_scopes(): void
    {
        config()->set('modules.enabled', ['tasks']);
        $this->useMigrationOnlySetupValidation();

        $this->assertSame(0, Artisan::call('engage:install', [
            '--no-create-user' => true,
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString('Requested modules: core, tasks', $output);
        $this->assertStringContainsString('Resolved modules: core, tasks', $output);
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'core',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'tasks',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseMissing('module_installations', [
            'module_key' => 'scheduling',
        ]);
        $this->assertFalse(Schema::hasTable('appointments'));
    }

    public function test_explicit_selection_rejects_omitted_configured_schema_before_database_mutation(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'scheduling',
        ]);

        $this->assertSame(1, Artisan::call('engage:install', [
            '--modules' => 'scheduling',
            '--no-create-user' => true,
        ]));

        $this->assertStringContainsString(
            'Installer module selection does not cover configured enabled schema scopes: [tasks].',
            Artisan::output(),
        );
        $this->assertFalse(Schema::hasTable('migrations'));
        $this->assertFalse(Schema::hasTable('module_installations'));
    }

    public function test_preset_failure_stops_before_validation_and_same_install_command_can_be_rerun(): void
    {
        config()->set('modules.enabled', ['scheduling']);
        $this->useMigrationOnlySetupValidation();

        $this->assertSame(1, Artisan::call('engage:install', [
            '--modules' => 'scheduling',
            '--preset' => 'missing_package',
            '--no-create-user' => true,
        ]));

        $failedOutput = Artisan::output();

        $this->assertStringContainsString(
            'Engage installation failed during [preset synchronization].',
            $failedOutput,
        );
        $this->assertStringNotContainsString('[4/4] Setup validation', $failedOutput);
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'core',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'scheduling',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);

        $migrationCountBeforeRetry = DB::table('migrations')->count();

        $this->assertSame(0, Artisan::call('engage:install', [
            '--modules' => 'scheduling',
            '--preset' => 'basic',
            '--no-create-user' => true,
        ]));

        $this->assertStringContainsString(
            'Engage installation completed successfully.',
            Artisan::output(),
        );
        $this->assertSame(
            $migrationCountBeforeRetry,
            DB::table('migrations')->count(),
        );
    }

    public function test_install_can_create_the_initial_user_without_internal_notifications(): void
    {
        config()->set('modules.enabled', ['tasks']);
        $this->useMigrationOnlySetupValidation();

        $this->artisan('engage:install', [
            '--modules' => 'tasks',
            '--create-user' => true,
        ])
            ->expectsQuestion('Name', 'Install Admin')
            ->expectsQuestion('Email', 'INSTALL.ADMIN@example.com')
            ->expectsQuestion('Password', 'install-test-password')
            ->expectsQuestion('Confirm password', 'install-test-password')
            ->assertExitCode(0);

        $user = User::query()
            ->where('email', 'install.admin@example.com')
            ->sole();

        $this->assertSame('Install Admin', $user->name);
        $this->assertTrue(Hash::check(
            'install-test-password',
            $user->password,
        ));
        $this->assertFalse(Schema::hasTable('team_members'));
    }

    public function test_mutually_exclusive_user_options_fail_before_database_mutation(): void
    {
        config()->set('modules.enabled', ['tasks']);

        $this->assertSame(1, Artisan::call('engage:install', [
            '--create-user' => true,
            '--no-create-user' => true,
        ]));

        $this->assertStringContainsString(
            'Installer options --create-user and --no-create-user are mutually exclusive.',
            Artisan::output(),
        );
        $this->assertFalse(Schema::hasTable('migrations'));
        $this->assertFalse(Schema::hasTable('users'));
    }

    protected function tearDown(): void
    {
        /*
        * This test class deliberately destroys and reconstructs the schema
        * outside RefreshDatabase. Ensure the next RefreshDatabase test does
        * not mistake whatever schema this test left behind for the complete
        * migrated test schema.
        */
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    private function useMigrationOnlySetupValidation(): void
    {
        $this->app->instance(
            SetupValidationManager::class,
            new SetupValidationManager([
                app(ModuleMigrationsSetupValidationContributor::class),
            ]),
        );
    }
}
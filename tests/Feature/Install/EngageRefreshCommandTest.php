<?php

namespace Tests\Feature\Install;

use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\SetupValidation\Contributors\ModuleMigrationsSetupValidationContributor;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\UsesSyntheticDeploymentEnvironment;
use Tests\TestCase;

class EngageRefreshCommandTest extends TestCase
{
    use UsesSyntheticDeploymentEnvironment;
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropAllTables();
        $this->useSyntheticDeploymentEnvironment();
    }

    public function test_refresh_refuses_incomplete_deployment_environment_before_database_wipe(): void
    {
        config()->set('modules.enabled', ['tasks']);
        $this->useSyntheticDeploymentEnvironment(
            clientOverrides: ['DB_PASSWORD' => ''],
        );

        Schema::create('refresh_deployment_guard_marker', function (Blueprint $table): void {
            $table->id();
        });

        $this->assertSame(1, Artisan::call('engage:refresh', [
            '--modules' => 'tasks',
            '--force' => true,
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString('Deployment preflight', $output);
        $this->assertStringContainsString(
            'Database refresh refused because deployment environment requirements are incomplete.',
            $output,
        );
        $this->assertStringContainsString('DB_PASSWORD', $output);
        $this->assertStringContainsString('No database changes were made.', $output);
        $this->assertStringNotContainsString('[1/2] Database wipe', $output);
        $this->assertTrue(Schema::hasTable('refresh_deployment_guard_marker'));
    }

    public function test_refresh_destroys_existing_schema_and_rebuilds_configured_engage_schema(): void
    {
        config()->set('modules.enabled', ['tasks']);
        $this->useMigrationOnlySetupValidation();

        Schema::create('obsolete_refresh_marker', function (Blueprint $table): void {
            $table->id();
        });

        $this->assertTrue(Schema::hasTable('obsolete_refresh_marker'));

        $this->assertSame(0, Artisan::call('engage:refresh', [
            '--modules' => 'tasks',
            '--force' => true,
        ]));

        $output = Artisan::output();

        $this->assertStringContainsString(
            '[1/2] Database wipe',
            $output,
        );
        $this->assertStringContainsString(
            '[2/2] Engage installation',
            $output,
        );
        $this->assertStringContainsString(
            'Resolved modules: core, tasks',
            $output,
        );
        $this->assertStringContainsString(
            'CRM user creation skipped.',
            $output,
        );
        $this->assertStringContainsString(
            'Engage database refresh completed successfully.',
            $output,
        );

        $this->assertFalse(Schema::hasTable('obsolete_refresh_marker'));
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('contacts'));
        $this->assertTrue(Schema::hasTable('tasks'));

        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'core',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseHas('module_installations', [
            'module_key' => 'tasks',
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
    }

    public function test_refresh_refuses_production_before_database_mutation(): void
    {
        Schema::create('refresh_guard_marker', function (Blueprint $table): void {
            $table->id();
        });

        $originalEnvironment = $this->app->environment();

        $this->app->instance('env', 'production');

        $exitCode = Artisan::call('engage:refresh', [
            '--force' => true,
        ]);

        $output = Artisan::output();

        $this->app->instance('env', $originalEnvironment);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString(
            'Refusing database refresh in environment [production].',
            $output,
        );
        $this->assertTrue(Schema::hasTable('refresh_guard_marker'));
    }

    public function test_refresh_requires_exact_confirmation_without_force(): void
    {
        Schema::create('refresh_confirmation_marker', function (Blueprint $table): void {
            $table->id();
        });

        $this->artisan('engage:refresh')
            ->expectsQuestion(
                'Type REFRESH DATABASE to continue',
                'no',
            )
            ->expectsOutput('Database refresh cancelled.')
            ->assertExitCode(1);

        $this->assertTrue(
            Schema::hasTable('refresh_confirmation_marker'),
        );
    }

    protected function tearDown(): void
    {
        /*
        * This test class deliberately destroys and reconstructs the schema
        * outside RefreshDatabase. Leave no partial or marker schema behind,
        * then force the next RefreshDatabase test to rebuild completely.
        */
        try {
            Schema::dropAllTables();
        } finally {
            RefreshDatabaseState::$migrated = false;

            parent::tearDown();
        }
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
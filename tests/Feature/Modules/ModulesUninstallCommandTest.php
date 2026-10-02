<?php

namespace Tests\Feature\Modules;

use App\Support\Modules\Migrations\ModuleInstallation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ModulesUninstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_MODULE = 'module_uninstall_fixture';

    private const DEPENDENT_MODULE = 'module_uninstall_dependent';

    private const FIXTURE_TABLE = 'module_uninstall_fixture_records';

    private const DEPENDENT_TABLE = 'module_uninstall_dependent_records';

    private const FIXTURE_MIGRATION = '2099_01_01_000001_create_module_uninstall_fixture_records_table';

    private const DEPENDENT_MIGRATION = '2099_01_01_000002_create_module_uninstall_dependent_records_table';

    protected function setUp(): void
    {
        parent::setUp();

        $this->writeMigrationFixture(
            moduleKey: self::FIXTURE_MODULE,
            migrationName: self::FIXTURE_MIGRATION,
            table: self::FIXTURE_TABLE,
        );
        $this->writeMigrationFixture(
            moduleKey: self::DEPENDENT_MODULE,
            migrationName: self::DEPENDENT_MIGRATION,
            table: self::DEPENDENT_TABLE,
        );

        $definitions = config('modules.modules', []);
        $definitions = is_array($definitions) ? $definitions : [];
        $definitions[self::FIXTURE_MODULE] = [
            'name' => 'Module Uninstall Fixture',
            'always_on' => false,
            'depends_on' => ['core'],
            'providers' => [],
        ];
        $definitions[self::DEPENDENT_MODULE] = [
            'name' => 'Module Uninstall Dependent',
            'always_on' => false,
            'depends_on' => [self::FIXTURE_MODULE],
            'providers' => [],
        ];
        config()->set('modules.modules', $definitions);

        $enabled = config('modules.enabled', []);
        $enabled = is_array($enabled) ? $enabled : [];
        config()->set('modules.enabled', array_values(array_diff(
            $enabled,
            [self::FIXTURE_MODULE, self::DEPENDENT_MODULE],
        )));

        $scopes = config('module_migrations.modules', []);
        $scopes = is_array($scopes) ? $scopes : [];
        $scopes[self::FIXTURE_MODULE] = [
            'path' => 'database/migrations/modules/'.self::FIXTURE_MODULE,
        ];
        $scopes[self::DEPENDENT_MODULE] = [
            'path' => 'database/migrations/modules/'.self::DEPENDENT_MODULE,
        ];
        config()->set('module_migrations.modules', $scopes);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists(self::DEPENDENT_TABLE);
        Schema::dropIfExists(self::FIXTURE_TABLE);

        if (Schema::hasTable('migrations')) {
            DB::table('migrations')
                ->whereIn('migration', [
                    self::FIXTURE_MIGRATION,
                    self::DEPENDENT_MIGRATION,
                ])
                ->delete();
        }

        if (Schema::hasTable('module_installations')) {
            ModuleInstallation::query()
                ->whereIn('module_key', [
                    self::FIXTURE_MODULE,
                    self::DEPENDENT_MODULE,
                ])
                ->delete();
        }

        $this->removeDirectory(
            database_path('migrations/modules/'.self::FIXTURE_MODULE),
        );
        $this->removeDirectory(
            database_path('migrations/modules/'.self::DEPENDENT_MODULE),
        );

        parent::tearDown();
    }

    public function test_uninstall_returns_module_to_clean_reinstallable_state(): void
    {
        $this->artisan('modules:install', [
            'module' => self::FIXTURE_MODULE,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasTable(self::FIXTURE_TABLE));
        $this->assertSame(1, DB::table('migrations')
            ->where('migration', self::FIXTURE_MIGRATION)
            ->count());
        $this->assertDatabaseHas('module_installations', [
            'module_key' => self::FIXTURE_MODULE,
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);

        $this->artisan('modules:uninstall', [
            'module' => self::FIXTURE_MODULE,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasTable(self::FIXTURE_TABLE));
        $this->assertSame(0, DB::table('migrations')
            ->where('migration', self::FIXTURE_MIGRATION)
            ->count());
        $this->assertDatabaseMissing('module_installations', [
            'module_key' => self::FIXTURE_MODULE,
        ]);

        $this->artisan('modules:uninstall', [
            'module' => self::FIXTURE_MODULE,
            '--force' => true,
        ])->assertSuccessful();

        $this->artisan('modules:install', [
            'module' => self::FIXTURE_MODULE,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasTable(self::FIXTURE_TABLE));
        $this->assertDatabaseHas('module_installations', [
            'module_key' => self::FIXTURE_MODULE,
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
    }

    public function test_uninstall_refuses_module_that_is_still_runtime_enabled(): void
    {
        $this->installFixture(self::FIXTURE_MODULE);
        config()->set('modules.enabled', [self::FIXTURE_MODULE]);

        $this->artisan('modules:uninstall', [
            'module' => self::FIXTURE_MODULE,
            '--force' => true,
        ])->assertFailed();

        $this->assertTrue(Schema::hasTable(self::FIXTURE_TABLE));
        $this->assertDatabaseHas('module_installations', [
            'module_key' => self::FIXTURE_MODULE,
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
    }

    public function test_uninstall_refuses_module_with_an_installed_dependent(): void
    {
        $this->installFixture(self::DEPENDENT_MODULE);

        $this->artisan('modules:uninstall', [
            'module' => self::FIXTURE_MODULE,
            '--force' => true,
        ])->assertFailed();

        $this->assertTrue(Schema::hasTable(self::FIXTURE_TABLE));
        $this->assertTrue(Schema::hasTable(self::DEPENDENT_TABLE));
        $this->assertDatabaseHas('module_installations', [
            'module_key' => self::FIXTURE_MODULE,
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
        $this->assertDatabaseHas('module_installations', [
            'module_key' => self::DEPENDENT_MODULE,
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
    }

    public function test_uninstall_requires_explicit_force_even_outside_production(): void
    {
        $this->installFixture(self::FIXTURE_MODULE);

        $this->artisan('modules:uninstall', [
            'module' => self::FIXTURE_MODULE,
        ])->assertFailed();

        $this->assertTrue(Schema::hasTable(self::FIXTURE_TABLE));
        $this->assertDatabaseHas('module_installations', [
            'module_key' => self::FIXTURE_MODULE,
            'status' => ModuleInstallation::STATUS_INSTALLED,
        ]);
    }

    private function installFixture(string $moduleKey): void
    {
        $this->artisan('modules:install', [
            'module' => $moduleKey,
            '--force' => true,
        ])->assertSuccessful();
    }

    private function writeMigrationFixture(
        string $moduleKey,
        string $migrationName,
        string $table,
    ): void {
        $directory = database_path('migrations/modules/'.$moduleKey);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $contents = <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table): void {
            \$table->id();
            \$table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP;

        file_put_contents(
            $directory.'/'.$migrationName.'.php',
            $contents,
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
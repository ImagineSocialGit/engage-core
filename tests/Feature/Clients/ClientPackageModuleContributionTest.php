<?php

namespace Tests\Feature\Clients;

use App\Support\Clients\ClientPackageManifest;
use App\Support\Clients\ClientPackageRuntime;
use App\Support\Modules\Migrations\ModuleMigrationPlanner;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Tests\TestCase;

class ClientPackageModuleContributionTest extends TestCase
{
    private string $migrationDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        ClientPackageRuntime::reset();
        config()->set('modules.enabled', []);

        $this->migrationDirectory = base_path(
            'client/package-module-fixture/vendor/imagine-social/fixture-vertical/database/migrations',
        );

        File::ensureDirectoryExists($this->migrationDirectory);
        File::put(
            $this->migrationDirectory.'/2099_01_01_000000_create_fixture_vertical_records.php',
            <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void {}

    public function down(): void {}
};
PHP,
        );
    }

    protected function tearDown(): void
    {
        ClientPackageRuntime::reset();
        File::deleteDirectory(base_path('client/package-module-fixture'));

        parent::tearDown();
    }

    public function test_package_module_is_absent_until_manifest_is_present_then_uses_normal_module_and_migration_planning(): void
    {
        $modules = app(ModuleManager::class);

        $this->assertFalse($modules->known('fixture_vertical'));

        ClientPackageRuntime::replace($this->fixtureManifest());

        $this->assertTrue($modules->known('fixture_vertical'));
        $this->assertFalse($modules->enabled('fixture_vertical'));
        $this->assertSame(['core'], $modules->dependencies('fixture_vertical'));
        $this->assertSame(
            [ClientPackageModuleContributionFixtureProvider::class],
            $modules->providers('fixture_vertical'),
        );

        $scope = app(ModuleMigrationRegistry::class)
            ->requireModule('fixture_vertical');

        $this->assertSame(
            'client/package-module-fixture/vendor/imagine-social/fixture-vertical/database/migrations',
            $scope->path,
        );
        $this->assertSame(
            ['2099_01_01_000000_create_fixture_vertical_records.php'],
            $scope->migrationFiles,
        );

        config()->set('modules.enabled', ['fixture_vertical']);

        $this->assertTrue($modules->enabled('fixture_vertical'));
        $this->assertSame(
            ['dashboard', 'core', 'fixture_vertical'],
            $modules->enabledKeysWithDependencies(),
        );

        $plan = app(ModuleMigrationPlanner::class)->forModule('fixture_vertical');

        $this->assertSame(
            ['core', 'fixture_vertical'],
            $plan->dependencyOrderedModuleKeys,
        );
        $this->assertSame(
            ['core', 'fixture_vertical'],
            array_map(
                static fn ($migrationScope): string => (string) $migrationScope->moduleKey,
                $plan->migrationScopes,
            ),
        );
    }

    public function test_package_module_cannot_replace_a_built_in_module_definition(): void
    {
        ClientPackageRuntime::replace(new ClientPackageManifest(
            moduleDefinitions: [
                'core' => [
                    'name' => 'Not Core',
                    'depends_on' => [],
                    'providers' => [ClientPackageModuleContributionFixtureProvider::class],
                ],
            ],
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conflict with Engage Core module key');

        app(ModuleManager::class)->definitions();
    }

    public function test_package_migration_scope_cannot_replace_a_built_in_scope(): void
    {
        ClientPackageRuntime::replace(new ClientPackageManifest(
            migrationScopes: [
                'core' => [
                    'path' => 'client/package-module-fixture/vendor/imagine-social/fixture-vertical/database/migrations',
                ],
            ],
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conflict with Engage Core migration scope key');

        app(ModuleMigrationRegistry::class)->definitions();
    }

    private function fixtureManifest(): ClientPackageManifest
    {
        return new ClientPackageManifest(
            moduleDefinitions: [
                'fixture_vertical' => [
                    'name' => 'Fixture Vertical',
                    'depends_on' => ['core'],
                    'providers' => [ClientPackageModuleContributionFixtureProvider::class],
                ],
            ],
            migrationScopes: [
                'fixture_vertical' => [
                    'path' => 'client/package-module-fixture/vendor/imagine-social/fixture-vertical/database/migrations',
                ],
            ],
        );
    }
}

final class ClientPackageModuleContributionFixtureProvider extends ServiceProvider
{
    //
}
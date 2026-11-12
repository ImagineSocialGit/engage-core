<?php

namespace Tests\Feature\Modules;

use App\Support\Clients\ClientPackageManifest;
use App\Support\Clients\ClientPackageRuntime;
use App\Support\Modules\Migrations\MigrationScopeDefinition;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class StandalonePackageTestMigrationScopeTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $directory = realpath(sys_get_temp_dir());
        $this->temporaryRoot = ($directory === false ? sys_get_temp_dir() : $directory)
            .'/engage-test-package-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        ClientPackageRuntime::reset();
        File::deleteDirectory($this->temporaryRoot);

        parent::tearDown();
    }

    public function test_test_package_migrations_register_from_canonical_source_checkout(): void
    {
        $path = $this->temporaryRoot.'/engage-fixture/database/migrations';
        File::ensureDirectoryExists($path);
        File::put(
            $path.'/2099_01_01_000000_create_fixture_records.php',
            '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void {} public function down(): void {} };',
        );

        ClientPackageRuntime::replace(new ClientPackageManifest(
            moduleDefinitions: [
                'fixture_vertical' => ['name' => 'Fixture Vertical', 'depends_on' => ['core']],
            ],
            migrationScopes: [
                'fixture_vertical' => ['path' => $path],
            ],
        ));

        $scope = $this->app->make(ModuleMigrationRegistry::class)
            ->requireModule('fixture_vertical');

        $this->assertSame($path, $scope->path);
        $this->assertSame(
            ['2099_01_01_000000_create_fixture_records.php'],
            $scope->migrationFiles,
        );
    }

    public function test_test_package_scope_rejects_noncanonical_path(): void
    {
        $path = $this->temporaryRoot.'/engage-fixture/database/migrations';
        File::ensureDirectoryExists($path);

        $this->expectException(InvalidArgumentException::class);

        MigrationScopeDefinition::module('fixture_vertical', [
            'path' => $this->temporaryRoot.'/engage-fixture/../engage-fixture/database/migrations',
        ]);
    }
}
<?php

namespace Tests\Feature\Clients;

use App\Support\Clients\ClientPackageManifestLoader;
use App\Support\Clients\ClientPackageRuntime;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Tests\TestCase;

class ClientPackageManifestLoaderTest extends TestCase
{
    private string $root;

    private string|false $originalClientKey;

    protected function setUp(): void
    {
        parent::setUp();

        ClientPackageRuntime::reset();
        unset($GLOBALS['engage_client_package_autoload_loaded']);

        $this->originalClientKey = getenv('CLIENT_KEY');
        $this->root = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR.'engage-core-client-packages-'.bin2hex(random_bytes(8));

        mkdir($this->root.DIRECTORY_SEPARATOR.'client', 0777, true);
    }

    protected function tearDown(): void
    {
        ClientPackageRuntime::reset();
        unset($GLOBALS['engage_client_package_autoload_loaded']);

        if ($this->originalClientKey === false) {
            putenv('CLIENT_KEY');
            unset($_ENV['CLIENT_KEY'], $_SERVER['CLIENT_KEY']);
        } else {
            putenv('CLIENT_KEY='.$this->originalClientKey);
            $_ENV['CLIENT_KEY'] = $this->originalClientKey;
            $_SERVER['CLIENT_KEY'] = $this->originalClientKey;
        }

        $this->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_manifest_loads_client_composer_autoloader_provider_and_environment_definitions(): void
    {
        $clientDirectory = $this->clientDirectory();
        mkdir($clientDirectory.'/config', 0777, true);
        mkdir($clientDirectory.'/vendor', 0777, true);

        file_put_contents(
            $clientDirectory.'/vendor/autoload.php',
            "<?php\n\$GLOBALS['engage_client_package_autoload_loaded'] = true;\n",
        );

        file_put_contents(
            $clientDirectory.'/config/client_packages.php',
            '<?php return '.var_export([
                'providers' => [
                    ClientPackageManifestLoaderFixtureProvider::class,
                ],
                'environment' => [
                    'CLIENT_PACKAGE_FIXTURE_TOKEN' => [
                        'owner' => 'fixture-package',
                        'secret' => true,
                    ],
                    'CLIENT_PACKAGE_FIXTURE_REGION' => [
                        'owner' => 'fixture-package',
                        'secret' => false,
                    ],
                ],
            ], true).';',
        );

        $this->selectClient('acme');

        $manifest = (new ClientPackageManifestLoader())->load($this->root);

        $this->assertTrue(
            (bool) ($GLOBALS['engage_client_package_autoload_loaded'] ?? false),
        );
        $this->assertSame(
            [ClientPackageManifestLoaderFixtureProvider::class],
            $manifest->providers(),
        );

        $definitions = $manifest->environmentDefinitions();

        $this->assertSame(
            EnvironmentVariableDefinition::SCOPE_CLIENT,
            $definitions['CLIENT_PACKAGE_FIXTURE_TOKEN']->scope,
        );
        $this->assertSame(
            'fixture-package',
            $definitions['CLIENT_PACKAGE_FIXTURE_TOKEN']->owner,
        );
        $this->assertTrue(
            $definitions['CLIENT_PACKAGE_FIXTURE_TOKEN']->secret,
        );
        $this->assertFalse(
            $definitions['CLIENT_PACKAGE_FIXTURE_REGION']->secret,
        );
    }

    public function test_manifest_rejects_provider_registration_without_installed_client_composer_dependencies(): void
    {
        $clientDirectory = $this->clientDirectory();
        mkdir($clientDirectory.'/config', 0777, true);

        file_put_contents(
            $clientDirectory.'/config/client_packages.php',
            '<?php return '.var_export([
                'providers' => [
                    ClientPackageManifestLoaderFixtureProvider::class,
                ],
            ], true).';',
        );

        $this->selectClient('acme');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'declares providers but Composer dependencies are not installed',
        );

        (new ClientPackageManifestLoader())->load($this->root);
    }

    public function test_client_composer_runtime_rejects_a_second_laravel_or_illuminate_tree(): void
    {
        $clientDirectory = $this->clientDirectory();
        mkdir($clientDirectory.'/vendor/illuminate/support', 0777, true);
        file_put_contents($clientDirectory.'/vendor/autoload.php', "<?php\n");

        $this->selectClient('acme');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'include a second Laravel/Illuminate runtime',
        );

        (new ClientPackageManifestLoader())->load($this->root);
    }

    public function test_manifest_rejects_environment_keys_owned_by_the_engage_catalog(): void
    {
        $clientDirectory = $this->clientDirectory();
        mkdir($clientDirectory.'/config', 0777, true);

        file_put_contents(
            $clientDirectory.'/config/client_packages.php',
            '<?php return '.var_export([
                'environment' => [
                    'APP_KEY' => [
                        'owner' => 'fixture-package',
                        'secret' => true,
                    ],
                ],
            ], true).';',
        );

        $this->selectClient('acme');

        $this->expectException(RuntimeException::class);

        (new ClientPackageManifestLoader())->load($this->root);
    }

    public function test_manifest_rejects_classes_that_are_not_service_providers(): void
    {
        $clientDirectory = $this->clientDirectory();
        mkdir($clientDirectory.'/config', 0777, true);
        mkdir($clientDirectory.'/vendor', 0777, true);

        file_put_contents($clientDirectory.'/vendor/autoload.php', "<?php\n");

        file_put_contents(
            $clientDirectory.'/config/client_packages.php',
            '<?php return '.var_export([
                'providers' => [
                    ClientPackageManifestLoaderFixtureNotProvider::class,
                ],
            ], true).';',
        );

        $this->selectClient('acme');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must extend');

        (new ClientPackageManifestLoader())->load($this->root);
    }

    private function clientDirectory(): string
    {
        return $this->root.DIRECTORY_SEPARATOR.'client'.DIRECTORY_SEPARATOR.'acme';
    }

    private function selectClient(string $clientKey): void
    {
        putenv('CLIENT_KEY='.$clientKey);
        $_ENV['CLIENT_KEY'] = $clientKey;
        $_SERVER['CLIENT_KEY'] = $clientKey;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            is_dir($path)
                ? $this->deleteDirectory($path)
                : unlink($path);
        }

        rmdir($directory);
    }
}

final class ClientPackageManifestLoaderFixtureProvider extends ServiceProvider
{
    //
}

final class ClientPackageManifestLoaderFixtureNotProvider
{
    //
}
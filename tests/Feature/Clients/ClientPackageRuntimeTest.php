<?php

namespace Tests\Feature\Clients;

use App\Providers\ClientServiceProvider;
use App\Support\Clients\ClientEnvironmentLoader;
use App\Support\Clients\ClientPackageManifest;
use App\Support\Clients\ClientPackageRuntime;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use App\Support\Environment\EnvironmentVariableCatalog;
use Illuminate\Support\ServiceProvider;
use stdClass;
use Tests\TestCase;

class ClientPackageRuntimeTest extends TestCase
{
    private string $root;

    /**
     * @var array<string, array{environment: string|false, env_exists: bool, env: mixed, server_exists: bool, server: mixed}>
     */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        ClientPackageRuntime::reset();

        $this->root = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR.'engage-core-client-package-runtime-'.bin2hex(random_bytes(8));

        mkdir($this->root.DIRECTORY_SEPARATOR.'client', 0777, true);

        foreach ([
            'CLIENT_KEY',
            ...ClientEnvironmentLoader::clientOwnedKeys(),
            'CLIENT_PACKAGE_RUNTIME_TOKEN',
        ] as $key) {
            $this->rememberEnvironment($key);
        }
    }

    protected function tearDown(): void
    {
        ClientPackageRuntime::reset();
        $this->restoreEnvironment();
        $this->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_runtime_environment_definitions_extend_the_existing_client_environment_contract(): void
    {
        ClientPackageRuntime::replace(new ClientPackageManifest(
            environmentDefinitions: [
                'CLIENT_PACKAGE_RUNTIME_TOKEN' => new EnvironmentVariableDefinition(
                    key: 'CLIENT_PACKAGE_RUNTIME_TOKEN',
                    scope: EnvironmentVariableDefinition::SCOPE_CLIENT,
                    owner: 'runtime-fixture',
                    secret: true,
                ),
            ],
        ));

        $definition = EnvironmentVariableCatalog::definition(
            'CLIENT_PACKAGE_RUNTIME_TOKEN',
        );

        $this->assertSame(
            EnvironmentVariableDefinition::SCOPE_CLIENT,
            $definition->scope,
        );
        $this->assertSame('runtime-fixture', $definition->owner);
        $this->assertTrue($definition->secret);
        $this->assertContains(
            'CLIENT_PACKAGE_RUNTIME_TOKEN',
            ClientEnvironmentLoader::clientOwnedKeys(),
        );
    }

    public function test_client_environment_loader_accepts_runtime_package_keys_without_weakening_builtin_ownership(): void
    {
        ClientPackageRuntime::replace(new ClientPackageManifest(
            environmentDefinitions: [
                'CLIENT_PACKAGE_RUNTIME_TOKEN' => new EnvironmentVariableDefinition(
                    key: 'CLIENT_PACKAGE_RUNTIME_TOKEN',
                    scope: EnvironmentVariableDefinition::SCOPE_CLIENT,
                    owner: 'runtime-fixture',
                    secret: true,
                ),
            ],
        ));

        $clientDirectory = $this->root.'/client/acme';
        mkdir($clientDirectory, 0777, true);

        file_put_contents(
            $clientDirectory.'/.env',
            "CLIENT_PACKAGE_RUNTIME_TOKEN=fixture-secret\n",
        );

        $this->setEnvironment('CLIENT_KEY', 'acme');

        (new ClientEnvironmentLoader())->load($this->root);

        $this->assertSame(
            'fixture-secret',
            getenv('CLIENT_PACKAGE_RUNTIME_TOKEN'),
        );
        $this->assertSame(
            EnvironmentVariableDefinition::SCOPE_ROOT,
            EnvironmentVariableCatalog::definition('APP_KEY')->scope,
        );
    }

    public function test_client_service_provider_registers_declared_package_providers_after_client_config(): void
    {
        ClientPackageRuntime::replace(new ClientPackageManifest(
            providers: [
                ClientPackageRuntimeFixtureProvider::class,
            ],
        ));

        config()->set(
            'client.config_path',
            $this->root.'/client/missing/config',
        );

        (new ClientServiceProvider($this->app))->register();

        $this->assertTrue(
            $this->app->bound('client-package-runtime-fixture'),
        );
        $this->assertInstanceOf(
            stdClass::class,
            $this->app->make('client-package-runtime-fixture'),
        );
    }

    private function setEnvironment(string $key, string $value): void
    {
        $this->rememberEnvironment($key);

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function rememberEnvironment(string $key): void
    {
        if (array_key_exists($key, $this->originalEnvironment)) {
            return;
        }

        $this->originalEnvironment[$key] = [
            'environment' => getenv($key),
            'env_exists' => array_key_exists($key, $_ENV),
            'env' => $_ENV[$key] ?? null,
            'server_exists' => array_key_exists($key, $_SERVER),
            'server' => $_SERVER[$key] ?? null,
        ];
    }

    private function restoreEnvironment(): void
    {
        foreach ($this->originalEnvironment as $key => $original) {
            $original['environment'] === false
                ? putenv($key)
                : putenv("{$key}={$original['environment']}");

            if ($original['env_exists']) {
                $_ENV[$key] = $original['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($original['server_exists']) {
                $_SERVER[$key] = $original['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }

        $this->originalEnvironment = [];
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

final class ClientPackageRuntimeFixtureProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            'client-package-runtime-fixture',
            static fn (): stdClass => new stdClass(),
        );
    }
}
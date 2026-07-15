<?php

namespace Tests\Feature\Clients;

use App\Support\Clients\ClientEnvironmentLoader;
use RuntimeException;
use Tests\TestCase;

class ClientEnvironmentLoaderTest extends TestCase
{
    /**
     * @var array<string, array{environment: string|false, env_exists: bool, env: mixed, server_exists: bool, server: mixed}>
     */
    private array $originalEnvironment = [];

    /**
     * @var array<int, string>
     */
    private array $tempDirectories = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'CLIENT_KEY',
            ...ClientEnvironmentLoader::clientOwnedKeys(),
        ] as $key) {
            $this->rememberEnvironment($key);
        }
    }

    protected function tearDown(): void
    {
        $this->restoreEnvironment();

        foreach ($this->tempDirectories as $directory) {
            $this->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_selected_client_environment_overrides_stale_root_values_for_client_owned_keys(): void
    {
        $root = $this->makeTempDirectory();
        $clientDirectory = $root.'/client/acme';

        mkdir($clientDirectory, 0777, true);

        file_put_contents($clientDirectory.'/.env', implode(PHP_EOL, [
            'APP_URL=https://acme.example.test',
            'DB_DATABASE=acme_database',
            'RESEND_API_KEY=acme-resend-key',
            '',
        ]));

        $this->setEnvironment('CLIENT_KEY', 'acme');
        $this->setEnvironment('APP_URL', 'https://stale-root.example.test');
        $this->setEnvironment('DB_DATABASE', 'stale_root_database');
        $this->setEnvironment('RESEND_API_KEY', 'stale-root-resend-key');

        (new ClientEnvironmentLoader())->load($root);

        $this->assertSame('https://acme.example.test', env('APP_URL'));
        $this->assertSame('acme_database', env('DB_DATABASE'));
        $this->assertSame('acme-resend-key', env('RESEND_API_KEY'));
    }

    public function test_switching_clients_clears_client_owned_values_missing_from_the_new_client(): void
    {
        $root = $this->makeTempDirectory();

        mkdir($root.'/client/alpha', 0777, true);
        mkdir($root.'/client/bravo', 0777, true);

        file_put_contents($root.'/client/alpha/.env', "RESEND_API_KEY=alpha-key\n");
        file_put_contents($root.'/client/bravo/.env', "APP_URL=https://bravo.example.test\n");

        $this->setEnvironment('CLIENT_KEY', 'alpha');

        $loader = new ClientEnvironmentLoader();
        $loader->load($root);

        $this->assertSame('alpha-key', env('RESEND_API_KEY'));

        $this->setEnvironment('CLIENT_KEY', 'bravo');
        $loader->load($root);

        $this->assertNull(env('RESEND_API_KEY'));
        $this->assertSame('https://bravo.example.test', env('APP_URL'));
    }

    public function test_client_environment_rejects_root_owned_or_unsupported_keys(): void
    {
        $root = $this->makeTempDirectory();
        $clientDirectory = $root.'/client/acme';

        mkdir($clientDirectory, 0777, true);

        file_put_contents($clientDirectory.'/.env', implode(PHP_EOL, [
            'APP_KEY=base64:not-client-owned',
            'QUEUE_CONNECTION=sync',
            '',
        ]));

        $this->setEnvironment('CLIENT_KEY', 'acme');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'contains root-owned or unsupported key(s): APP_KEY, QUEUE_CONNECTION.'
        );

        (new ClientEnvironmentLoader())->load($root);
    }

    private function makeTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/engage-core-client-env-'.bin2hex(random_bytes(8));

        mkdir($directory, 0777, true);

        $this->tempDirectories[] = $directory;

        return $directory;
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

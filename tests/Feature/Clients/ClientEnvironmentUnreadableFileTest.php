<?php

namespace Tests\Feature\Clients;

use App\Support\Clients\ClientEnvironmentLoader;
use App\Support\Environment\EnvironmentVariableCatalog;
use RuntimeException;
use Tests\TestCase;

class ClientEnvironmentUnreadableFileTest extends TestCase
{
    private string $temporaryRoot;

    /**
     * @var array<string, string|false>
     */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryRoot = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR.'engage-core-client-env-unreadable-'.bin2hex(random_bytes(8));

        mkdir($this->temporaryRoot.DIRECTORY_SEPARATOR.'client', 0777, true);

        foreach ([
            'CLIENT_KEY',
            ...EnvironmentVariableCatalog::clientOwnedKeys(),
        ] as $key) {
            $this->previousEnvironment[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $environmentPath = $this->temporaryRoot.'/client/acme/.env';
        if (is_file($environmentPath)) {
            chmod($environmentPath, 0600);
            unlink($environmentPath);
        }

        if (is_dir($this->temporaryRoot.'/client/acme')) {
            rmdir($this->temporaryRoot.'/client/acme');
        }

        if (is_dir($this->temporaryRoot.'/client')) {
            rmdir($this->temporaryRoot.'/client');
        }

        if (is_dir($this->temporaryRoot)) {
            rmdir($this->temporaryRoot);
        }

        parent::tearDown();
    }

    public function test_existing_unreadable_selected_client_environment_fails_loudly(): void
    {
        $clientDirectory = $this->temporaryRoot.'/client/acme';
        $environmentPath = $clientDirectory.'/.env';

        mkdir($clientDirectory, 0777, true);
        file_put_contents($environmentPath, implode(PHP_EOL, [
            'APP_URL=http://engagecore.test',
            'DB_DATABASE=acme_local',
            'DB_USERNAME=acme_local',
            '',
        ]));
        chmod($environmentPath, 0000);
        clearstatcache(true, $environmentPath);

        if (is_readable($environmentPath)) {
            $this->markTestSkipped(
                'Current test process can bypass Unix mode bits; unreadable-file behavior cannot be exercised under this identity.',
            );
        }

        putenv('CLIENT_KEY=acme');
        $_ENV['CLIENT_KEY'] = 'acme';
        $_SERVER['CLIENT_KEY'] = 'acme';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Selected client environment ['.$environmentPath.'] is not readable by the current process.',
        );

        (new ClientEnvironmentLoader())->load($this->temporaryRoot);
    }
}
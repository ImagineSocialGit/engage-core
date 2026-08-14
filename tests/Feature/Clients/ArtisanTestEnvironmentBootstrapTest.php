<?php

namespace Tests\Feature\Clients;

use App\Support\Testing\ArtisanTestEnvironmentBootstrap;
use RuntimeException;
use Tests\TestCase;

class ArtisanTestEnvironmentBootstrapTest extends TestCase
{
    /**
     * @var array<string, array{environment: string|false, env_exists: bool, env: mixed, server_exists: bool, server: mixed}>
     */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['APP_ENV', 'DB_DATABASE'] as $key) {
            $this->originalEnvironment[$key] = [
                'environment' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->originalEnvironment) as $key) {
            $this->restoreEnvironmentValue($key);
        }

        parent::tearDown();
    }

    public function test_artisan_test_command_forces_testing_environment_and_acquires_database_lock(): void
    {
        $database = $this->uniqueDatabaseName('environment');

        $this->setEnvironmentValue('APP_ENV', 'local');
        $this->setEnvironmentValue('DB_DATABASE', $database);

        $lock = ArtisanTestEnvironmentBootstrap::prepare(
            'test',
            $this->basePathWithoutPhpUnitConfiguration(),
        );

        try {
            $this->assertSame('testing', getenv('APP_ENV'));
            $this->assertSame('testing', $_ENV['APP_ENV'] ?? null);
            $this->assertSame('testing', $_SERVER['APP_ENV'] ?? null);
            $this->assertSame($database, $lock?->database());
        } finally {
            $lock?->release();
        }
    }

    public function test_forced_phpunit_database_wins_over_shell_database_for_lock_identity(): void
    {
        $shellDatabase = $this->uniqueDatabaseName('shell');
        $phpUnitDatabase = $this->uniqueDatabaseName('phpunit');
        $directory = $this->temporaryConfigurationDirectory();

        file_put_contents(
            $directory.'/phpunit.xml',
            <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <phpunit>
                <php>
                    <env name="DB_DATABASE" value="{$phpUnitDatabase}" force="true"/>
                </php>
            </phpunit>
            XML,
        );

        $this->setEnvironmentValue('APP_ENV', 'local');
        $this->setEnvironmentValue('DB_DATABASE', $shellDatabase);

        $lock = ArtisanTestEnvironmentBootstrap::prepare(
            'test',
            $directory,
        );

        try {
            $this->assertSame($phpUnitDatabase, $lock?->database());
        } finally {
            $lock?->release();
            $this->removeTemporaryDirectory($directory);
        }
    }

    public function test_competing_test_command_is_rejected_for_the_same_database(): void
    {
        $database = $this->uniqueDatabaseName('competition');

        $this->setEnvironmentValue('DB_DATABASE', $database);

        $first = ArtisanTestEnvironmentBootstrap::prepare(
            'test',
            $this->basePathWithoutPhpUnitConfiguration(),
        );

        try {
            try {
                ArtisanTestEnvironmentBootstrap::prepare(
                    'test',
                    $this->basePathWithoutPhpUnitConfiguration(),
                );

                $this->fail(
                    'Expected the second test bootstrap to reject the occupied database lock.',
                );
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(
                    "already using [{$database}]",
                    $exception->getMessage(),
                );
                $this->assertStringContainsString(
                    'Concurrent suites against the same destructive test database are not supported.',
                    $exception->getMessage(),
                );
                $this->assertStringContainsString(
                    'Current lock owner:',
                    $exception->getMessage(),
                );
            }
        } finally {
            $first?->release();
        }

        $second = ArtisanTestEnvironmentBootstrap::prepare(
            'test',
            $this->basePathWithoutPhpUnitConfiguration(),
        );

        $this->assertSame($database, $second?->database());

        $second?->release();
    }

    public function test_non_test_artisan_commands_do_not_change_the_environment_or_acquire_a_lock(): void
    {
        $this->setEnvironmentValue('APP_ENV', 'local');

        $lock = ArtisanTestEnvironmentBootstrap::prepare(
            'migrate',
            base_path(),
        );

        $this->assertNull($lock);
        $this->assertSame('local', getenv('APP_ENV'));
        $this->assertSame('local', $_ENV['APP_ENV'] ?? null);
        $this->assertSame('local', $_SERVER['APP_ENV'] ?? null);
    }

    public function test_similarly_named_commands_do_not_activate_the_test_environment(): void
    {
        $this->setEnvironmentValue('APP_ENV', 'local');

        $lock = ArtisanTestEnvironmentBootstrap::prepare(
            'test:custom',
            base_path(),
        );

        $this->assertNull($lock);
        $this->assertSame('local', getenv('APP_ENV'));
        $this->assertSame('local', $_ENV['APP_ENV'] ?? null);
        $this->assertSame('local', $_SERVER['APP_ENV'] ?? null);
    }

    private function uniqueDatabaseName(string $suffix): string
    {
        return sprintf(
            'engagecore_test_bootstrap_%s_%s',
            $suffix,
            bin2hex(random_bytes(6)),
        );
    }

    private function basePathWithoutPhpUnitConfiguration(): string
    {
        $directory = $this->temporaryConfigurationDirectory();

        register_shutdown_function(
            fn () => $this->removeTemporaryDirectory($directory),
        );

        return $directory;
    }

    private function temporaryConfigurationDirectory(): string
    {
        $directory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .'engage-core-bootstrap-test-'
            .bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException(
                "Unable to create temporary test directory [{$directory}].",
            );
        }

        return $directory;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (glob($directory.'/*') ?: [] as $path) {
            @unlink($path);
        }

        @rmdir($directory);
    }

    private function setEnvironmentValue(
        string $key,
        string $value,
    ): void {
        putenv("{$key}={$value}");

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function restoreEnvironmentValue(string $key): void
    {
        $original = $this->originalEnvironment[$key];

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
}
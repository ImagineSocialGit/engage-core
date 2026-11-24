<?php

namespace Tests\Unit\Runtime;

use App\Support\Clients\ClientEnvironmentLoader;
use App\Support\Deployment\Data\DeploymentPlan;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Deployment\EnvironmentFileSynchronizer;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Tests\TestCase;
use RuntimeException;

final class ExternalSelectedClientConsumersTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/engage-core-external-consumer-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/client/acme', 0700, true);
        mkdir($this->root.'/shared/runtime/config/r1', 0700, true);
        mkdir($this->root.'/shared/runtime/cache/c1', 0700, true);
        file_put_contents($this->root.'/client/acme/.env', "APP_URL=https://legacy.invalid\n");
        file_put_contents($this->generation().'/root.env', "APP_ENV=staging\nCLIENT_KEY=acme\n");
        file_put_contents($this->generation().'/client.env', "APP_URL=https://external.example.test\n");
        chmod($this->generation().'/root.env', 0600);
        chmod($this->generation().'/client.env', 0600);
        foreach (['ENGAGE_RUNTIME_CONFIG_DIR', 'APP_CONFIG_CACHE', 'CLIENT_KEY', 'APP_URL'] as $key) {
            $this->original[$key] = getenv($key);
        }
        putenv('ENGAGE_RUNTIME_CONFIG_DIR='.$this->generation());
        putenv('APP_CONFIG_CACHE='.$this->root.'/shared/runtime/cache/c1/config.php');
        putenv('CLIENT_KEY=acme');
        $_ENV['CLIENT_KEY'] = 'acme';
        $_SERVER['CLIENT_KEY'] = 'acme';
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function generation(): string
    {
        return $this->root.'/shared/runtime/config/r1';
    }

    public function test_loader_and_deployment_readers_agree_on_external_generation(): void
    {
        (new ClientEnvironmentLoader())->load($this->root);
        self::assertSame('https://external.example.test', getenv('APP_URL'));

        $repository = new EnvironmentFileRepository();
        self::assertSame($this->generation().'/client.env', $repository->pathForScope(EnvironmentVariableDefinition::SCOPE_CLIENT));
        self::assertSame($this->generation().'/root.env', $repository->pathForScope(EnvironmentVariableDefinition::SCOPE_ROOT));
        self::assertSame('https://external.example.test', $repository->valuesForScope(EnvironmentVariableDefinition::SCOPE_CLIENT)['APP_URL']);
    }

    public function test_missing_external_client_env_does_not_fall_back_to_legacy(): void
    {
        unlink($this->generation().'/client.env');
        $this->expectException(RuntimeException::class);
        (new ClientEnvironmentLoader())->load($this->root);
    }

    public function test_application_setup_cannot_edit_external_generation(): void
    {
        $plan = new DeploymentPlan('staging', 'acme', [], [], [], []);
        $before = file_get_contents($this->generation().'/client.env');
        try {
            (new EnvironmentFileSynchronizer(new EnvironmentFileRepository()))->writeMissingRequiredKeys($plan);
            self::fail('Setup must reject writes to external generations.');
        } catch (RuntimeException) {
            self::assertSame($before, file_get_contents($this->generation().'/client.env'));
        }
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory.'/'.$item;
            is_dir($path) && ! is_link($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}

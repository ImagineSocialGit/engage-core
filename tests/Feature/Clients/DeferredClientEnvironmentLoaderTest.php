<?php

namespace Tests\Feature\Clients;

use App\Support\Clients\ClientEnvironmentLoader;
use App\Support\Clients\ClientPackageManifest;
use App\Support\Clients\ClientPackageRuntime;
use App\Support\Deployment\Contracts\DeploymentEnvironmentDefinitionContributor;
use App\Support\Environment\Data\EnvironmentVariableDefinition;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeferredClientEnvironmentLoaderTest extends TestCase
{
    private const KEY = 'FIXTURE_INTERNAL_CALLER_SECRET';
    private Container $previousContainer;
    private ClientPackageManifest $previousManifest;
    private Container $container;
    private array $environment = [];
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $this->previousManifest = ClientPackageRuntime::manifest();
        $this->container = new Container();
        Container::setInstance($this->container);
        ClientPackageRuntime::replace(ClientPackageManifest::empty());
        foreach (['CLIENT_KEY', self::KEY, ...ClientEnvironmentLoader::clientOwnedKeys()] as $key) {
            $this->environment[$key] = [getenv($key), array_key_exists($key, $_ENV), $_ENV[$key] ?? null,
                array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null];
        }
        $this->root = sys_get_temp_dir().'/core-deferred-env-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/client/fixture-core', 0700, true);
        $this->put('CLIENT_KEY', 'fixture-core');
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => [$process, $hasEnv, $env, $hasServer, $server]) {
            $process === false ? putenv($key) : putenv($key.'='.$process);
            if ($hasEnv) { $_ENV[$key] = $env; } else { unset($_ENV[$key]); }
            if ($hasServer) { $_SERVER[$key] = $server; } else { unset($_SERVER[$key]); }
        }
        @unlink($this->root.'/client/fixture-core/.env');
        @rmdir($this->root.'/client/fixture-core');
        @rmdir($this->root.'/client');
        @rmdir($this->root);
        ClientPackageRuntime::replace($this->previousManifest);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_dynamic_secret_is_deferred_until_its_owner_has_registered(): void
    {
        file_put_contents($this->root.'/client/fixture-core/.env', "APP_URL=http://fixture.test\n".self::KEY.'='.str_repeat('A', 64)."\n");
        $this->put(self::KEY, 'STALE_ROOT_VALUE');
        $loader = new ClientEnvironmentLoader();
        $loader->load($this->root, deferUnknownKeys: true);
        self::assertFalse(getenv(self::KEY));
        self::assertSame('http://fixture.test', getenv('APP_URL'));
        $this->registerOwner();
        $loader->load($this->root);
        self::assertSame(str_repeat('A', 64), getenv(self::KEY));
    }

    public function test_unknown_values_are_rejected_by_the_mandatory_strict_pass(): void
    {
        file_put_contents($this->root.'/client/fixture-core/.env', self::KEY."=DO_NOT_EXPOSE\n");
        $loader = new ClientEnvironmentLoader();
        $loader->load($this->root, deferUnknownKeys: true);
        self::assertFalse(getenv(self::KEY));
        try {
            $loader->load($this->root);
            self::fail('Unowned values must not survive final validation.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('DO_NOT_EXPOSE', $exception->getMessage());
        }
    }

    public function test_root_owned_keys_are_rejected_even_during_the_early_pass(): void
    {
        file_put_contents($this->root.'/client/fixture-core/.env', "APP_KEY=DO_NOT_EXPOSE\n");
        $this->expectException(RuntimeException::class);
        (new ClientEnvironmentLoader())->load($this->root, deferUnknownKeys: true);
    }

    private function registerOwner(): void
    {
        $owner = new class implements DeploymentEnvironmentDefinitionContributor {
            public function owner(): string { return 'fixture'; }
            public function environmentRequirements(): iterable { return []; }
            public function environmentDefinitions(): iterable
            {
                yield new EnvironmentVariableDefinition('FIXTURE_INTERNAL_CALLER_SECRET', 'client', 'fixture', true);
            }
        };
        $this->container->instance('fixture.owner', $owner);
        $this->container->tag('fixture.owner', 'deployment.plan_contributors');
    }

    private function put(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
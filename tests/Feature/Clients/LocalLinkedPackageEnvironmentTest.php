<?php

namespace Tests\Feature\Clients;

use App\Support\Clients\ClientEnvironmentLoader;
use App\Support\Clients\ClientPackageManifestLoader;
use App\Support\Clients\ClientPackageRuntime;
use App\Support\Environment\EnvironmentVariableCatalog;
use RuntimeException;
use Tests\TestCase;

final class LocalLinkedPackageEnvironmentTest extends TestCase
{
    private string $workspace;

    /** @var array<string, array{string|false, bool, mixed, bool, mixed}> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/engage-linked-package-'.bin2hex(random_bytes(8));
        mkdir($this->workspace.'/engage-core/client/fixture/config', 0777, true);
        mkdir($this->workspace.'/engage-core/client/fixture/vendor/imagine-social', 0777, true);
        mkdir($this->workspace.'/engage-fixture', 0777, true);

        $this->setEnv('APP_ENV', 'local');
        $this->setEnv('CLIENT_KEY', 'fixture');
        $this->remember('FIXTURE_NEW_TOKEN');
        $this->remember('FIXTURE_OLD_TOKEN');
        ClientPackageRuntime::reset();

        $this->writeRegistry();
        $this->writeSourceDescriptor();
        $this->link();
    }

    protected function tearDown(): void
    {
        ClientPackageRuntime::reset();

        foreach ($this->original as $key => [$process, $hadEnv, $envValue, $hadServer, $serverValue]) {
            $process === false ? putenv($key) : putenv($key.'='.$process);

            if ($hadEnv) {
                $_ENV[$key] = $envValue;
            } else {
                unset($_ENV[$key]);
            }

            if ($hadServer) {
                $_SERVER[$key] = $serverValue;
            } else {
                unset($_SERVER[$key]);
            }
        }

        $this->deleteTree($this->workspace);
        parent::tearDown();
    }

    public function test_registered_local_link_uses_new_environment_keys_without_writing_client_files(): void
    {
        $registryPath = $this->clientRoot().'/config/client_package_registry.json';
        $manifestPath = $this->clientRoot().'/config/client_packages.php';
        $registered = file_get_contents($registryPath);
        $generated = file_get_contents($manifestPath);

        $this->writeSourceDescriptor([
            'FIXTURE_NEW_TOKEN' => ['owner' => 'fixture', 'secret' => true],
        ]);
        $this->setEnv('FIXTURE_NEW_TOKEN', 'stale-root-value');
        file_put_contents($this->clientRoot().'/.env', "FIXTURE_NEW_TOKEN=local-only-value\n");

        $manifest = (new ClientPackageManifestLoader())->load($this->coreRoot());
        ClientPackageRuntime::replace($manifest);

        $this->assertArrayHasKey('FIXTURE_NEW_TOKEN', $manifest->environmentDefinitions());
        $this->assertArrayNotHasKey('FIXTURE_OLD_TOKEN', $manifest->environmentDefinitions());
        $this->assertTrue($manifest->environmentDefinitions()['FIXTURE_NEW_TOKEN']->secret);
        $this->assertContains('FIXTURE_NEW_TOKEN', ClientEnvironmentLoader::clientOwnedKeys());
        (new ClientEnvironmentLoader())->load($this->coreRoot());
        $this->assertSame('local-only-value', getenv('FIXTURE_NEW_TOKEN'));
        $this->assertSame($registered, file_get_contents($registryPath));
        $this->assertSame($generated, file_get_contents($manifestPath));
    }

    public function test_removed_local_environment_key_is_cleared_on_next_load(): void
    {
        $this->setEnv('FIXTURE_OLD_TOKEN', 'stale-secret');
        file_put_contents($this->clientRoot().'/.env', "FIXTURE_OLD_TOKEN=old-secret\n");
        $loader = new ClientEnvironmentLoader();

        ClientPackageRuntime::replace((new ClientPackageManifestLoader())->load($this->coreRoot()));
        $loader->load($this->coreRoot());
        $this->assertSame('old-secret', getenv('FIXTURE_OLD_TOKEN'));

        $this->writeSourceDescriptor(['FIXTURE_NEW_TOKEN' => ['owner' => 'fixture', 'secret' => true]]);
        file_put_contents($this->clientRoot().'/.env', "FIXTURE_NEW_TOKEN=new-secret\n");

        ClientPackageRuntime::replace((new ClientPackageManifestLoader())->load($this->coreRoot()));
        $loader->load($this->coreRoot());

        $this->assertFalse(getenv('FIXTURE_OLD_TOKEN'));
        $this->assertSame('new-secret', getenv('FIXTURE_NEW_TOKEN'));
    }

    public function test_changed_environment_metadata_has_no_effect_without_a_local_link(): void
    {
        unlink($this->linkPath());
        $this->writeSourceDescriptor(['FIXTURE_NEW_TOKEN' => ['owner' => 'fixture', 'secret' => true]]);

        $manifest = (new ClientPackageManifestLoader())->load($this->coreRoot());

        $this->assertArrayHasKey('FIXTURE_OLD_TOKEN', $manifest->environmentDefinitions());
        $this->assertArrayNotHasKey('FIXTURE_NEW_TOKEN', $manifest->environmentDefinitions());
    }

    public function test_production_and_staging_ignore_local_symlink_metadata(): void
    {
        $this->writeSourceDescriptor(['FIXTURE_NEW_TOKEN' => ['owner' => 'fixture', 'secret' => true]]);

        foreach (['staging', 'production'] as $environment) {
            $this->setEnv('APP_ENV', $environment);
            $manifest = (new ClientPackageManifestLoader())->load($this->coreRoot());
            $this->assertArrayHasKey('FIXTURE_OLD_TOKEN', $manifest->environmentDefinitions());
            $this->assertArrayNotHasKey('FIXTURE_NEW_TOKEN', $manifest->environmentDefinitions());
        }
    }

    public function test_changed_provider_or_module_contract_requires_explicit_metadata_review(): void
    {
        $this->writeSourceDescriptor(
            ['FIXTURE_NEW_TOKEN' => ['owner' => 'fixture', 'secret' => true]],
            ['service_provider' => 'ImagineSocial\\Fixture\\ChangedProvider'],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--sync-metadata');
        (new ClientPackageManifestLoader())->load($this->coreRoot());
    }

    public function test_retargeted_package_symlink_outside_workspace_is_rejected(): void
    {
        unlink($this->linkPath());
        symlink($this->coreRoot(), $this->linkPath());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the trusted Core sibling');
        (new ClientPackageManifestLoader())->load($this->coreRoot());
    }

    public function test_source_descriptor_composer_identity_mismatch_is_rejected(): void
    {
        file_put_contents(
            $this->workspace.'/engage-fixture/composer.json',
            json_encode(['name' => 'imagine-social/another-package'], JSON_THROW_ON_ERROR),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Composer identity');
        (new ClientPackageManifestLoader())->load($this->coreRoot());
    }

    public function test_root_owned_environment_key_cannot_be_claimed_by_package(): void
    {
        $this->writeSourceDescriptor(['APP_KEY' => ['owner' => 'fixture', 'secret' => true]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conflicts with an Engage-owned environment variable');
        (new ClientPackageManifestLoader())->load($this->coreRoot());
    }

    public function test_malformed_environment_declaration_is_rejected_before_loading_any_secrets(): void
    {
        $this->writeSourceDescriptor(['FIXTURE_NEW_TOKEN' => ['owner' => 'fixture', 'secret' => 'yes']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid environment declaration');
        (new ClientPackageManifestLoader())->load($this->coreRoot());
    }

    public function test_unknown_environment_key_is_still_rejected_by_client_loader(): void
    {
        file_put_contents($this->clientRoot().'/.env', "FIXTURE_UNREGISTERED_SECRET=sensitive\n");
        $manifest = (new ClientPackageManifestLoader())->load($this->coreRoot());
        ClientPackageRuntime::replace($manifest);

        try {
            (new ClientEnvironmentLoader())->load($this->coreRoot());
            $this->fail('Unknown keys must not become client-owned.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('FIXTURE_UNREGISTERED_SECRET', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive', $exception->getMessage());
        }
    }

    public function test_cross_package_environment_ownership_collision_is_rejected(): void
    {
        $second = $this->workspace.'/engage-other';
        mkdir($second);
        file_put_contents($second.'/composer.json', json_encode(['name' => 'imagine-social/engage-other'], JSON_THROW_ON_ERROR));
        $secondDescriptor = $this->descriptor();
        $secondDescriptor['name'] = 'imagine-social/engage-other';
        $secondDescriptor['environment'] = ['FIXTURE_OTHER_TOKEN' => ['owner' => 'other', 'secret' => true]];
        file_put_contents($second.'/engage-package.json', json_encode($secondDescriptor, JSON_THROW_ON_ERROR));
        $this->writeSourceDescriptor(['FIXTURE_OTHER_TOKEN' => ['owner' => 'fixture', 'secret' => true]]);

        $registry = $this->registry();
        $registry['packages'][] = $secondDescriptor;
        $this->writeRegistry($registry);
        symlink($second, $this->clientRoot().'/vendor/imagine-social/engage-other');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('conflicts with an already registered environment key');
        (new ClientPackageManifestLoader())->load($this->coreRoot());
    }

    /** @param array<string, array{owner: string, secret: mixed}>|null $environment */
    private function writeSourceDescriptor(?array $environment = null, array $overrides = []): void
    {
        $descriptor = array_replace($this->descriptor(), $overrides);

        if ($environment !== null) {
            $descriptor['environment'] = $environment;
        }

        file_put_contents(
            $this->workspace.'/engage-fixture/engage-package.json',
            json_encode($descriptor, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
        file_put_contents(
            $this->workspace.'/engage-fixture/composer.json',
            json_encode(['name' => 'imagine-social/engage-fixture'], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function descriptor(): array
    {
        return [
            'schema' => 1,
            'name' => 'imagine-social/engage-fixture',
            'kind' => 'library',
            'platform' => 'core',
            'namespace' => 'ImagineSocial\\Fixture',
            'github_owner' => 'ImagineSocialGit',
            'environment' => [
                'FIXTURE_OLD_TOKEN' => ['owner' => 'fixture', 'secret' => false],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function registry(): array
    {
        return ['schema' => 1, 'packages' => [$this->descriptor()]];
    }

    /** @param array<string, mixed>|null $registry */
    private function writeRegistry(?array $registry = null): void
    {
        $registry ??= $this->registry();
        $environment = [];

        foreach ($registry['packages'] as $descriptor) {
            foreach ($descriptor['environment'] as $key => $definition) {
                // The generated manifest is purposely a snapshot of the
                // registered descriptors, not the currently linked sources.
                $environment[$key] = $definition;
            }
        }

        file_put_contents(
            $this->clientRoot().'/config/client_package_registry.json',
            json_encode($registry, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
        );
        file_put_contents(
            $this->clientRoot().'/config/client_packages.php',
            "<?php\n\n// Generated by scripts/package.sh from config/client_package_registry.json.\n\nreturn "
            .var_export(['providers' => [], 'modules' => [], 'migrations' => [], 'environment' => $environment], true)
            .";\n",
        );
    }

    private function link(): void
    {
        symlink($this->workspace.'/engage-fixture', $this->linkPath());
    }

    private function linkPath(): string
    {
        return $this->clientRoot().'/vendor/imagine-social/engage-fixture';
    }

    private function clientRoot(): string
    {
        return $this->coreRoot().'/client/fixture';
    }

    private function coreRoot(): string
    {
        return $this->workspace.'/engage-core';
    }

    private function setEnv(string $key, string $value): void
    {
        $this->remember($key);
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function remember(string $key): void
    {
        if (array_key_exists($key, $this->original)) {
            return;
        }

        $this->original[$key] = [
            getenv($key),
            array_key_exists($key, $_ENV), $_ENV[$key] ?? null,
            array_key_exists($key, $_SERVER), $_SERVER[$key] ?? null,
        ];
    }

    private function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (new \FilesystemIterator($path) as $entry) {
            $this->deleteTree($entry->getPathname());
        }

        rmdir($path);
    }
}
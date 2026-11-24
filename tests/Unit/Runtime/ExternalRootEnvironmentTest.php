<?php

namespace Tests\Unit\Runtime;

use App\Support\Runtime\ExternalRootEnvironment;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExternalRootEnvironmentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/engage-external-root-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/shared/runtime/config/r1', 0700, true);
        mkdir($this->root.'/shared/runtime/cache/c1', 0700, true);
        file_put_contents($this->root.'/shared/runtime/config/r1/root.env', "APP_ENV=staging\n");
        chmod($this->root.'/shared/runtime/config/r1/root.env', 0600);
    }

    protected function tearDown(): void
    {
        foreach (['shared/runtime/config/r1/root.env', 'shared/runtime/config/r1/alias.env',
            'shared/runtime/cache/c1/config.php'] as $file) {
            if (is_file($this->root.'/'.$file) || is_link($this->root.'/'.$file)) {
                unlink($this->root.'/'.$file);
            }
        }
        foreach (['shared/runtime/config/r1', 'shared/runtime/cache/c1',
            'shared/runtime/config', 'shared/runtime/cache', 'shared/runtime', 'shared'] as $dir) {
            if (is_dir($this->root.'/'.$dir)) {
                rmdir($this->root.'/'.$dir);
            }
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function config(): string
    {
        return $this->root.'/shared/runtime/config/r1';
    }

    private function cache(): string
    {
        return $this->root.'/shared/runtime/cache/c1/config.php';
    }

    public function test_private_external_generation_passes_without_cache_yet(): void
    {
        ExternalRootEnvironment::assertApprovedPaths($this->config(), $this->cache());
        self::assertTrue(true);
    }

    public function test_external_root_rejects_world_readable_secrets(): void
    {
        chmod($this->config().'/root.env', 0644);
        $this->expectException(RuntimeException::class);
        ExternalRootEnvironment::assertApprovedPaths($this->config(), $this->cache());
    }

    public function test_external_root_rejects_hardlinked_secret(): void
    {
        link($this->config().'/root.env', $this->config().'/alias.env');
        $this->expectException(RuntimeException::class);
        ExternalRootEnvironment::assertApprovedPaths($this->config(), $this->cache());
    }

    public function test_external_root_rejects_cache_outside_instance(): void
    {
        $this->expectException(RuntimeException::class);
        ExternalRootEnvironment::assertApprovedPaths($this->config(), '/tmp/config.php');
    }

    public function test_external_root_rejects_symlinked_secret(): void
    {
        unlink($this->config().'/root.env');
        symlink('/etc/passwd', $this->config().'/root.env');
        $this->expectException(RuntimeException::class);
        ExternalRootEnvironment::assertApprovedPaths($this->config(), $this->cache());
    }

    public function test_external_root_rejects_unsafe_directory_permissions(): void
    {
        chmod($this->config(), 0770);
        $this->expectException(RuntimeException::class);
        ExternalRootEnvironment::assertApprovedPaths($this->config(), $this->cache());
    }
    public function test_disabled_external_mode_preserves_normal_laravel_environment_path(): void
    {
        $previous = getenv('ENGAGE_RUNTIME_CONFIG_DIR');
        putenv('ENGAGE_RUNTIME_CONFIG_DIR');
        try {
            $application = new Application($this->root);
            $before = $application->environmentFilePath();
            ExternalRootEnvironment::configure($application);
            self::assertSame($before, $application->environmentFilePath());
        } finally {
            $previous === false
                ? putenv('ENGAGE_RUNTIME_CONFIG_DIR')
                : putenv('ENGAGE_RUNTIME_CONFIG_DIR='.$previous);
        }
    }

    public function test_opt_in_configures_external_root_before_laravel_bootstraps(): void
    {
        $priorDirectory = getenv('ENGAGE_RUNTIME_CONFIG_DIR');
        $priorCache = getenv('APP_CONFIG_CACHE');
        putenv('ENGAGE_RUNTIME_CONFIG_DIR='.$this->config());
        putenv('APP_CONFIG_CACHE='.$this->cache());
        try {
            $application = new Application($this->root);
            ExternalRootEnvironment::configure($application);
            self::assertSame($this->config().'/root.env', $application->environmentFilePath());
            self::assertSame($this->cache(), $application->getCachedConfigPath());
        } finally {
            $priorDirectory === false
                ? putenv('ENGAGE_RUNTIME_CONFIG_DIR')
                : putenv('ENGAGE_RUNTIME_CONFIG_DIR='.$priorDirectory);
            $priorCache === false
                ? putenv('APP_CONFIG_CACHE')
                : putenv('APP_CONFIG_CACHE='.$priorCache);
        }
    }

    public function test_external_root_requires_dedicated_cache_binding(): void
    {
        $priorDirectory = getenv('ENGAGE_RUNTIME_CONFIG_DIR');
        $priorCache = getenv('APP_CONFIG_CACHE');
        putenv('ENGAGE_RUNTIME_CONFIG_DIR='.$this->config());
        putenv('APP_CONFIG_CACHE');
        try {
            $this->expectException(RuntimeException::class);
            ExternalRootEnvironment::configure(new Application($this->root));
        } finally {
            $priorDirectory === false
                ? putenv('ENGAGE_RUNTIME_CONFIG_DIR')
                : putenv('ENGAGE_RUNTIME_CONFIG_DIR='.$priorDirectory);
            $priorCache === false
                ? putenv('APP_CONFIG_CACHE')
                : putenv('APP_CONFIG_CACHE='.$priorCache);
        }
    }

}

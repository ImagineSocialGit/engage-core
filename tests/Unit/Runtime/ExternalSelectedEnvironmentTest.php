<?php

namespace Tests\Unit\Runtime;

use App\Support\Runtime\ExternalSelectedEnvironment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ExternalSelectedEnvironmentTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/engage-selected-config-'.bin2hex(random_bytes(8));
        mkdir($this->root.'/shared/runtime/config/r1', 0700, true);
        mkdir($this->root.'/shared/runtime/cache/c1', 0700, true);
        $this->write('root.env', "APP_ENV=staging\nCLIENT_KEY=fixture-seo\n");
        $this->write('client.env', "APP_URL=https://fixture.test\n");
        foreach (['ENGAGE_RUNTIME_CONFIG_DIR', 'APP_CONFIG_CACHE'] as $key) {
            $this->original[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $key => $value) {
            $value === false ? putenv($key) : putenv($key.'='.$value);
        }
        $this->deleteDirectory($this->root);
        parent::tearDown();
    }

    private function generation(): string
    {
        return $this->root.'/shared/runtime/config/r1';
    }

    private function write(string $name, string $contents): void
    {
        file_put_contents($this->generation().'/'.$name, $contents);
        chmod($this->generation().'/'.$name, 0600);
    }

    private function enable(): void
    {
        putenv('ENGAGE_RUNTIME_CONFIG_DIR='.$this->generation());
        putenv('APP_CONFIG_CACHE='.$this->root.'/shared/runtime/cache/c1/config.php');
    }

    public function test_legacy_mode_has_no_external_paths(): void
    {
        putenv('ENGAGE_RUNTIME_CONFIG_DIR');
        self::assertFalse(ExternalSelectedEnvironment::enabled());
        self::assertNull(ExternalSelectedEnvironment::rootPath());
        self::assertNull(ExternalSelectedEnvironment::clientPath());
    }

    public function test_external_mode_resolves_both_files_in_the_same_generation(): void
    {
        $this->enable();
        self::assertTrue(ExternalSelectedEnvironment::enabled());
        self::assertSame($this->generation().'/root.env', ExternalSelectedEnvironment::rootPath());
        self::assertSame($this->generation().'/client.env', ExternalSelectedEnvironment::clientPath());
    }

    public function test_missing_client_file_must_not_fall_back_to_source(): void
    {
        $this->enable();
        unlink($this->generation().'/client.env');
        $this->expectException(RuntimeException::class);
        ExternalSelectedEnvironment::clientPath();
    }

    public function test_symlinked_client_file_is_rejected(): void
    {
        $this->enable();
        rename($this->generation().'/client.env', $this->generation().'/original');
        symlink($this->generation().'/original', $this->generation().'/client.env');
        $this->expectException(RuntimeException::class);
        ExternalSelectedEnvironment::clientPath();
    }

    public function test_hardlinked_client_file_is_rejected(): void
    {
        $this->enable();
        link($this->generation().'/client.env', $this->generation().'/alias');
        $this->expectException(RuntimeException::class);
        ExternalSelectedEnvironment::clientPath();
    }

    public function test_world_readable_client_file_is_rejected(): void
    {
        $this->enable();
        chmod($this->generation().'/client.env', 0644);
        $this->expectException(RuntimeException::class);
        ExternalSelectedEnvironment::clientPath();
    }

    public function test_missing_cache_binding_is_rejected(): void
    {
        $this->enable();
        putenv('APP_CONFIG_CACHE');
        $this->expectException(RuntimeException::class);
        ExternalSelectedEnvironment::clientPath();
    }

    public function test_source_file_must_not_be_accepted_as_external_client_file(): void
    {
        $this->enable();
        unlink($this->generation().'/client.env');
        symlink('/etc/passwd', $this->generation().'/client.env');
        $this->expectException(RuntimeException::class);
        ExternalSelectedEnvironment::rootPath();
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

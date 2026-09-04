<?php

namespace Tests\Feature\Storage;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientScopedSpacesFilesystemTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $originalSpacesConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalSpacesConfig = config('filesystems.disks.spaces', []);
    }

    protected function tearDown(): void
    {
        Storage::forgetDisk('spaces');
        config()->set('filesystems.disks.spaces', $this->originalSpacesConfig);

        parent::tearDown();
    }

    public function test_spaces_disk_root_tracks_the_selected_client_key(): void
    {
        $spaces = config('filesystems.disks.spaces', []);

        $this->assertArrayHasKey('root', $spaces);
        $this->assertSame(
            trim((string) env('CLIENT_KEY', ''), '/'),
            $spaces['root'],
        );
    }

    public function test_spaces_public_urls_apply_the_client_root_once(): void
    {
        $this->configureSpacesDisk();

        $this->assertSame(
            'https://cdn.example.test/thompson-square-engage/media/asset-id/asset.jpg',
            Storage::disk('spaces')->url('media/asset-id/asset.jpg'),
        );
    }

    public function test_cdn_image_uses_the_same_client_scoped_spaces_disk(): void
    {
        $this->configureSpacesDisk();

        $this->assertSame(
            'https://cdn.example.test/thompson-square-engage/images/hero/960.webp',
            cdn_image('hero', '960.webp'),
        );

        $this->assertSame(
            'https://cdn.example.test/thompson-square-engage/images/hero',
            cdn_image('hero'),
        );
    }

    private function configureSpacesDisk(): void
    {
        Storage::forgetDisk('spaces');

        config()->set('filesystems.disks.spaces', [
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'nyc3',
            'bucket' => 'test-bucket',
            'endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'url' => 'https://cdn.example.test',
            'root' => 'thompson-square-engage',
            'use_path_style_endpoint' => false,
            'throw' => true,
            'report' => false,
        ]);
    }
}
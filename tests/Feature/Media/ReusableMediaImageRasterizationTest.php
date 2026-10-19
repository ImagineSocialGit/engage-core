<?php

namespace Tests\Feature\Media;

use App\Modules\Media\Contracts\ReusableMediaImageLibrary;
use App\Modules\Media\Data\ReusableMediaRenderedImage;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\MediaModuleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReusableMediaImageRasterizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['media']);
        config()->set('filesystems.disks.public', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/media-rasterization'),
            'url' => 'https://cdn.example.test',
        ]);
        Storage::fake('public');
        config()->set('filesystems.disks.public.url', 'https://cdn.example.test');
        
        $this->app->register(MediaModuleServiceProvider::class, force: true);
    }

    public function test_library_rasterizes_safe_svg_to_bounded_png_without_changing_asset_identity(): void
    {
        $asset = MediaAsset::factory()->create([
            'uuid' => '11111111-1111-4111-8111-111111111111',
            'kind' => MediaAsset::KIND_IMAGE,
            'disk' => 'public',
            'path' => 'media/logo/logo.svg',
            'mime_type' => 'image/svg+xml',
            'extension' => 'svg',
            'visibility' => MediaAsset::VISIBILITY_PUBLIC,
            'ingestion_status' => MediaAsset::INGESTION_READY,
            'archived_at' => null,
            'meta' => null,
        ]);
        Storage::disk('public')->put(
            $asset->path,
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100"><rect width="200" height="100" fill="#111827"/><circle cx="100" cy="50" r="30" fill="#ffffff"/></svg>',
        );

        $rendered = app(ReusableMediaImageLibrary::class)->renderedPng(
            uuid: (string) $asset->uuid,
            maximumWidth: 100,
            maximumHeight: 100,
        );

        $this->assertInstanceOf(ReusableMediaRenderedImage::class, $rendered);
        $this->assertSame((string) $asset->uuid, $rendered->source->uuid);
        $this->assertSame('image/svg+xml', $rendered->source->mimeType);
        $this->assertSame('image/png', $rendered->mimeType);
        $this->assertSame(100, $rendered->width);
        $this->assertSame(50, $rendered->height);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($rendered->bytes, 0, 8));

        $asset->refresh();
        $this->assertSame('media/logo/logo.svg', $asset->path);
        $this->assertSame('image/svg+xml', $asset->mime_type);
    }

    public function test_library_rasterizes_existing_png_with_aspect_ratio_preserved(): void
    {
        $source = imagecreatetruecolor(120, 60);
        $this->assertInstanceOf(\GdImage::class, $source);
        $background = imagecolorallocate($source, 20, 40, 60);
        $this->assertNotFalse($background);
        imagefill($source, 0, 0, $background);

        ob_start();
        imagepng($source);
        $bytes = ob_get_clean();
        imagedestroy($source);

        $this->assertIsString($bytes);

        $asset = MediaAsset::factory()->create([
            'kind' => MediaAsset::KIND_IMAGE,
            'disk' => 'public',
            'path' => 'media/logo/logo.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'visibility' => MediaAsset::VISIBILITY_PUBLIC,
            'ingestion_status' => MediaAsset::INGESTION_READY,
            'archived_at' => null,
        ]);
        Storage::disk('public')->put($asset->path, $bytes);

        $rendered = app(ReusableMediaImageLibrary::class)->renderedPng(
            uuid: (string) $asset->uuid,
            maximumWidth: 60,
            maximumHeight: 60,
        );

        $this->assertInstanceOf(ReusableMediaRenderedImage::class, $rendered);
        $this->assertSame(60, $rendered->width);
        $this->assertSame(30, $rendered->height);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($rendered->bytes, 0, 8));
    }

    public function test_library_returns_null_for_unreadable_or_unsupported_image_content(): void
    {
        $asset = MediaAsset::factory()->create([
            'kind' => MediaAsset::KIND_IMAGE,
            'disk' => 'public',
            'path' => 'media/logo/bad.svg',
            'mime_type' => 'image/svg+xml',
            'extension' => 'svg',
            'visibility' => MediaAsset::VISIBILITY_PUBLIC,
            'ingestion_status' => MediaAsset::INGESTION_READY,
            'archived_at' => null,
        ]);
        Storage::disk('public')->put(
            $asset->path,
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->assertNull(
            app(ReusableMediaImageLibrary::class)->renderedPng((string) $asset->uuid),
        );
    }
}
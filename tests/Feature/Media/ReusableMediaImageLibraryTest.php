<?php

namespace Tests\Feature\Media;

use App\Modules\Media\Contracts\ReusableMediaImageLibrary;
use App\Modules\Media\Data\ReusableMediaImageContent;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\MediaModuleServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReusableMediaImageLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['media']);
        config()->set('media.disk', 'public');
        config()->set('filesystems.disks.public.url', 'https://cdn.example.test');

        Storage::fake('public');
        $this->app->register(MediaModuleServiceProvider::class, force: true);
    }

    public function test_library_exposes_only_active_ready_public_images_and_original_bytes(): void
    {
        $eligible = MediaAsset::factory()->create([
            'disk' => 'public',
            'ingestion_status' => MediaAsset::INGESTION_READY,
        ]);
        $archived = MediaAsset::factory()->archived()->create([
            'disk' => 'public',
            'ingestion_status' => MediaAsset::INGESTION_READY,
        ]);
        $processing = MediaAsset::factory()->create([
            'disk' => 'public',
            'ingestion_status' => MediaAsset::INGESTION_PROCESSING,
        ]);
        $video = MediaAsset::factory()->video()->create([
            'disk' => 'public',
            'ingestion_status' => MediaAsset::INGESTION_READY,
        ]);
        $private = MediaAsset::factory()->create([
            'disk' => 'public',
            'ingestion_status' => MediaAsset::INGESTION_READY,
            'visibility' => 'private',
        ]);
        $generated = MediaAsset::factory()->create([
            'disk' => 'public',
            'ingestion_status' => MediaAsset::INGESTION_READY,
            'meta' => ['authoring_selectable' => false],
        ]);

        Storage::disk('public')->put($eligible->path, 'eligible-image-bytes');
        Storage::disk('public')->put($archived->path, 'archived-image-bytes');
        Storage::disk('public')->put($processing->path, 'processing-image-bytes');
        Storage::disk('public')->put($video->path, 'video-bytes');
        Storage::disk('public')->put($private->path, 'private-image-bytes');
        Storage::disk('public')->put($generated->path, 'generated-image-bytes');

        $library = app(ReusableMediaImageLibrary::class);
        $selectable = $library->selectable(['image/jpeg']);

        $this->assertSame(
            [(string) $eligible->uuid],
            array_map(
                static fn ($image): string => $image->uuid,
                $selectable,
            ),
        );

        $content = $library->content((string) $eligible->uuid);

        $this->assertInstanceOf(ReusableMediaImageContent::class, $content);
        $this->assertSame((string) $eligible->uuid, $content->image->uuid);
        $this->assertSame('image/jpeg', $content->image->mimeType);
        $this->assertSame('eligible-image-bytes', $content->bytes);

        $generatedContent = $library->content((string) $generated->uuid);
        $this->assertInstanceOf(ReusableMediaImageContent::class, $generatedContent);
        $this->assertSame('generated-image-bytes', $generatedContent->bytes);
        $this->assertSame(
            (string) $generated->uuid,
            $library->find((string) $generated->uuid)?->uuid,
        );

        $this->assertNull($library->find((string) $archived->uuid));
        $this->assertNull($library->find((string) $processing->uuid));
        $this->assertNull($library->find((string) $video->uuid));
        $this->assertNull($library->find((string) $private->uuid));
    }

    public function test_library_mime_filter_is_normalized_and_bounded_to_images(): void
    {
        $jpeg = MediaAsset::factory()->create([
            'disk' => 'public',
            'ingestion_status' => MediaAsset::INGESTION_READY,
        ]);
        $png = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/png/logo.png',
            'original_filename' => 'logo.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'ingestion_status' => MediaAsset::INGESTION_READY,
        ]);

        Storage::disk('public')->put($jpeg->path, 'jpeg');
        Storage::disk('public')->put($png->path, 'png');

        $images = app(ReusableMediaImageLibrary::class)
            ->selectable([' IMAGE/PNG ', 'image/png']);

        $this->assertSame(
            [(string) $png->uuid],
            array_map(
                static fn ($image): string => $image->uuid,
                $images,
            ),
        );
    }
}
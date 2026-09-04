<?php

namespace Tests\Feature\Media;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Media\Jobs\GenerateMediaImageVariantsJob;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\MediaModuleServiceProvider;
use App\Modules\Media\Services\MediaImageVariantGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaImageVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['media']);
        config()->set('media.disk', 'public');
        config()->set('media.image_variants.enabled', true);
        config()->set('media.image_variants.medium_width', 500);
        config()->set('media.image_variants.default_max_width', 1920);
        config()->set('media.image_variants.webp_quality', 82);
        config()->set('media.image_variants.max_source_pixels', 40000000);
        Storage::fake('public', [
            'url' => 'https://cdn.example.test',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_new_image_upload_queues_derivatives_without_replacing_original_identity(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('crm.media.store'), [
                'title' => 'Progressive source',
                'file' => UploadedFile::fake()->image(
                    'progressive-source.jpg',
                    1200,
                    800,
                ),
            ])
            ->assertRedirect(route('crm.media.index'))
            ->assertSessionHas('media_upload_status', 'created');

        $asset = MediaAsset::query()->sole();

        Storage::disk('public')->assertExists($asset->path);
        $this->assertStringEndsWith('/'.$asset->uuid.'.jpg', $asset->path);
        $this->assertNotSame('', $asset->checksum_sha256);
        $this->assertFalse($asset->hasProgressiveImageVariants());

        Queue::assertPushed(
            GenerateMediaImageVariantsJob::class,
            fn (GenerateMediaImageVariantsJob $job): bool =>
                $job->mediaAssetId === $asset->getKey()
                && $job->queue === 'default',
        );
    }

    public function test_generator_creates_medium_and_display_webp_without_changing_original(): void
    {
        $generator = app(MediaImageVariantGenerator::class);

        if (! $generator->available()) {
            $this->markTestSkipped('GD WebP support is unavailable in this test runtime.');
        }

        $upload = UploadedFile::fake()->image('wide-source.jpg', 2400, 1200);
        $bytes = file_get_contents($upload->getRealPath());
        $this->assertIsString($bytes);

        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/variant-test/variant-test.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'checksum_sha256' => hash('sha256', $bytes),
            'image_width' => 2400,
            'image_height' => 1200,
            'meta' => ['keep_me' => 'yes'],
        ]);
        Storage::disk('public')->put($asset->path, $bytes, 'public');

        $originalPath = $asset->path;
        $originalChecksum = $asset->checksum_sha256;

        $this->assertTrue($generator->generate($asset));

        $asset->refresh();

        Storage::disk('public')->assertExists('media/variant-test/medium.webp');
        Storage::disk('public')->assertExists('media/variant-test/default.webp');
        $this->assertSame($originalPath, $asset->path);
        $this->assertSame($originalChecksum, $asset->checksum_sha256);
        $this->assertSame('yes', $asset->meta['keep_me']);
        $this->assertTrue($asset->hasProgressiveImageVariants());
        $this->assertSame(
            500,
            data_get($asset->meta, 'image_variants.files.medium.width'),
        );
        $this->assertSame(
            1920,
            data_get($asset->meta, 'image_variants.files.default.width'),
        );
        $this->assertSame(
            'https://cdn.example.test/media/variant-test/medium.webp',
            $asset->imageVariantUrl('medium'),
        );
        $this->assertSame(
            'https://cdn.example.test/media/variant-test/default.webp',
            $asset->imageVariantUrl('default'),
        );
    }

    public function test_progressive_component_uses_derivatives_and_falls_back_to_original(): void
    {
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/component-test/component-test.jpg',
            'meta' => [
                'image_variants' => [
                    'version' => MediaImageVariantGenerator::VERSION,
                    'files' => [
                        'medium' => [
                            'path' => 'media/component-test/medium.webp',
                            'width' => 500,
                            'height' => 333,
                        ],
                        'default' => [
                            'path' => 'media/component-test/default.webp',
                            'width' => 1200,
                            'height' => 800,
                        ],
                    ],
                ],
            ],
        ]);

        $html = Blade::render(
            '<x-media.progressive-image :asset="$asset" alt="Preview" class="aspect-video w-full object-cover" />',
            compact('asset'),
        );

        $this->assertStringContainsString('data-media-progressive-image', $html);
        $this->assertStringContainsString('/media/component-test/medium.webp', $html);
        $this->assertStringContainsString('/media/component-test/default.webp', $html);
        $this->assertStringNotContainsString('data-media-original-image', $html);

        $asset->forceFill(['meta' => null])->save();

        $fallback = Blade::render(
            '<x-media.progressive-image :asset="$asset" alt="Preview" />',
            compact('asset'),
        );

        $this->assertStringContainsString('data-media-original-image', $fallback);
        $this->assertStringContainsString('/media/component-test/component-test.jpg', $fallback);
    }

    public function test_backfill_command_is_registered_and_preserves_original_identity(): void
    {
        $generator = app(MediaImageVariantGenerator::class);

        if (! $generator->available()) {
            $this->markTestSkipped('GD WebP support is unavailable in this test runtime.');
        }

        $this->app->register(MediaModuleServiceProvider::class, force: true);

        $upload = UploadedFile::fake()->image('backfill.jpg', 900, 600);
        $bytes = file_get_contents($upload->getRealPath());
        $this->assertIsString($bytes);

        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/backfill-test/backfill-test.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'checksum_sha256' => hash('sha256', $bytes),
            'meta' => null,
        ]);
        Storage::disk('public')->put($asset->path, $bytes, 'public');

        $originalPath = $asset->path;
        $originalChecksum = $asset->checksum_sha256;

        $this->assertSame(0, Artisan::call('media:image-variants:backfill'));

        $asset->refresh();

        $this->assertTrue($asset->hasProgressiveImageVariants());
        $this->assertSame($originalPath, $asset->path);
        $this->assertSame($originalChecksum, $asset->checksum_sha256);
    }

    public function test_animated_gif_remains_original_only(): void
    {
        $generator = app(MediaImageVariantGenerator::class);
        $asset = MediaAsset::factory()->create([
            'disk' => 'public',
            'path' => 'media/gif-test/gif-test.gif',
            'mime_type' => 'image/gif',
            'extension' => 'gif',
            'meta' => null,
        ]);

        $this->assertFalse($generator->supports($asset));
        $this->assertFalse($asset->hasProgressiveImageVariants());
    }
}
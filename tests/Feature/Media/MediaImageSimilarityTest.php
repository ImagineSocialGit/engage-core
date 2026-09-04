<?php

namespace Tests\Feature\Media;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Media\Data\ImagePerceptualFingerprint;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Providers\MediaModuleServiceProvider;
use App\Modules\Media\Services\ImagePerceptualHasher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaImageSimilarityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['media']);
        config()->set('media.disk', 'public');
        config()->set('media.near_duplicate_images.enabled', true);
        config()->set('media.near_duplicate_images.max_hamming_distance', 8);
        config()->set('media.near_duplicate_images.aspect_ratio_tolerance', 0.08);
        config()->set('media.near_duplicate_images.max_candidates', 3);
        config()->set('filesystems.disks.public.url', 'https://cdn.example.test');
        Storage::fake('public');

        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_dhash_distance_is_portable_without_integer_bit_overflow(): void
    {
        $hasher = new ImagePerceptualHasher();

        $this->assertSame(
            0,
            $hasher->hammingDistance('0000000000000000', '0000000000000000'),
        );
        $this->assertSame(
            4,
            $hasher->hammingDistance('0000000000000000', '000000000000000f'),
        );
        $this->assertSame(
            64,
            $hasher->hammingDistance('0000000000000000', 'ffffffffffffffff'),
        );
        $this->assertNull(
            $hasher->hammingDistance('invalid', 'ffffffffffffffff'),
        );
    }

    public function test_similarity_preflight_ranks_only_close_active_images_without_storing_upload(): void
    {
        $user = User::factory()->create();
        $near = MediaAsset::factory()->create([
            'title' => 'Existing close image',
            'perceptual_hash' => '0000000000000000',
            'image_width' => 1200,
            'image_height' => 800,
        ]);
        MediaAsset::factory()->create([
            'title' => 'Visually distant image',
            'perceptual_hash' => 'ffffffffffffffff',
            'image_width' => 1200,
            'image_height' => 800,
        ]);
        MediaAsset::factory()->archived()->create([
            'title' => 'Archived close image',
            'perceptual_hash' => '0000000000000000',
            'image_width' => 1200,
            'image_height' => 800,
        ]);
        MediaAsset::factory()->create([
            'title' => 'Different aspect ratio',
            'perceptual_hash' => '0000000000000000',
            'image_width' => 800,
            'image_height' => 1200,
        ]);

        $this->app->instance(
            ImagePerceptualHasher::class,
            new class extends ImagePerceptualHasher {
                public function available(): bool
                {
                    return true;
                }

                public function fingerprint(UploadedFile $file): ?ImagePerceptualFingerprint
                {
                    return new ImagePerceptualFingerprint(
                        hash: '0000000000000001',
                        algorithm: self::ALGORITHM,
                        width: 1200,
                        height: 800,
                    );
                }
            },
        );

        $beforeCount = MediaAsset::query()->count();
        $beforeFiles = Storage::disk('public')->allFiles();

        $response = $this->actingAs($user)
            ->postJson(route('crm.media.similarity.inspect'), [
                'file' => $this->pngUpload('candidate.png'),
            ])
            ->assertOk()
            ->assertJsonPath('status', 'near_duplicate')
            ->assertJsonPath('algorithm', ImagePerceptualHasher::ALGORITHM)
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.id', $near->getKey())
            ->assertJsonPath('candidates.0.distance', 1);

        $this->assertFalse((bool) $response->json('candidates.0.archived'));
        $this->assertSame($beforeCount, MediaAsset::query()->count());
        $this->assertSame($beforeFiles, Storage::disk('public')->allFiles());
    }

    public function test_new_image_upload_persists_fingerprint_but_similarity_never_hard_blocks_storage(): void
    {
        $user = User::factory()->create();

        $this->app->instance(
            ImagePerceptualHasher::class,
            new class extends ImagePerceptualHasher {
                public function available(): bool
                {
                    return true;
                }

                public function fingerprint(UploadedFile $file): ?ImagePerceptualFingerprint
                {
                    return new ImagePerceptualFingerprint(
                        hash: '0123456789abcdef',
                        algorithm: self::ALGORITHM,
                        width: 640,
                        height: 360,
                    );
                }
            },
        );

        $this->actingAs($user)
            ->post(route('crm.media.store'), [
                'title' => 'Intentional visual variant',
                'file' => $this->pngUpload('variant.png'),
            ])
            ->assertRedirect(route('crm.media.index'))
            ->assertSessionHas('media_upload_status', 'created');

        $asset = MediaAsset::query()->sole();

        $this->assertSame('0123456789abcdef', $asset->perceptual_hash);
        $this->assertSame(ImagePerceptualHasher::ALGORITHM, $asset->perceptual_hash_algorithm);
        $this->assertSame(640, $asset->image_width);
        $this->assertSame(360, $asset->image_height);
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_backfill_populates_existing_image_fingerprints_without_rewriting_storage_identity(): void
    {
        $asset = MediaAsset::factory()->create([
            'perceptual_hash' => null,
            'perceptual_hash_algorithm' => null,
            'image_width' => null,
            'image_height' => null,
        ]);
        Storage::disk('public')->put($asset->path, 'historical-image-bytes');

        $this->app->instance(
            ImagePerceptualHasher::class,
            new class extends ImagePerceptualHasher {
                public function available(): bool
                {
                    return true;
                }

                public function fingerprintBytes(string $bytes): ?ImagePerceptualFingerprint
                {
                    return new ImagePerceptualFingerprint(
                        hash: 'aaaaaaaaaaaaaaaa',
                        algorithm: self::ALGORITHM,
                        width: 900,
                        height: 600,
                    );
                }
            },
        );

        $this->app->register(MediaModuleServiceProvider::class, force: true);

        $originalPath = $asset->path;
        $originalChecksum = $asset->checksum_sha256;

        $this->assertSame(0, Artisan::call('media:image-fingerprints:backfill'));

        $asset->refresh();

        $this->assertSame('aaaaaaaaaaaaaaaa', $asset->perceptual_hash);
        $this->assertSame(900, $asset->image_width);
        $this->assertSame(600, $asset->image_height);
        $this->assertSame($originalPath, $asset->path);
        $this->assertSame($originalChecksum, $asset->checksum_sha256);
    }

    private function pngUpload(string $name): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );

        $this->assertIsString($bytes);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}
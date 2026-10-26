<?php

namespace Tests\Feature\Media;

use App\Modules\Media\Actions\RecoverLostMediaProcessingJobsAction;
use App\Modules\Media\Jobs\GenerateMediaImageVariantsJob;
use App\Modules\Media\Jobs\ProcessMediaVideoIngestionJob;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\MediaImageVariantGenerator;
use App\Modules\Media\Services\MediaVideoIngestor;
use App\Support\Queues\QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

final class MediaRedisLossRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['media']);
        config()->set('media.disk', 'spaces');
        config()->set('media.image_variants.enabled', true);
        config()->set('media.video_ingestion.enabled', true);
        config()->set('media.video_ingestion.connection', 'redis-media');
        config()->set('media.video_ingestion.queue', QueueContract::MEDIA_PROCESSING);

        // This test probes binary availability, but never runs video ingestion:
        // Queue::fake() intercepts every recovered job. Use the PHP CLI for
        // the probe so the test does not depend on FFmpeg being installed.
        // MediaVideoIngestor is final and cannot be mocked by class name.
        config()->set('media.video_ingestion.ffmpeg_binary', PHP_BINARY);
        config()->set('media.video_ingestion.ffprobe_binary', PHP_BINARY);

        Storage::fake('spaces');
        Queue::fake();

        $images = Mockery::mock(MediaImageVariantGenerator::class);
        $images->shouldReceive('available')->andReturn(true);
        $images->shouldReceive('supports')->andReturn(true);
        $this->app->instance(MediaImageVariantGenerator::class, $images);

        $this->assertTrue(app(MediaVideoIngestor::class)->available());
    }

    public function test_lost_image_and_video_jobs_are_requeued_from_durable_assets_once(): void
    {
        $image = $this->oldImage();
        $video = $this->oldVideo();
        Storage::disk('spaces')->put((string) $image->path, 'durable original');
        Storage::disk('spaces')->put((string) $video->path, 'durable video source');

        $action = app(RecoverLostMediaProcessingJobsAction::class);

        $this->assertSame(['images' => 1, 'videos' => 1], $action->handle());
        Queue::assertPushed(GenerateMediaImageVariantsJob::class, 1);
        Queue::assertPushed(ProcessMediaVideoIngestionJob::class, 1);
        Queue::assertPushed(
            GenerateMediaImageVariantsJob::class,
            fn (GenerateMediaImageVariantsJob $job): bool =>
                $job->mediaAssetId === (int) $image->getKey()
                && $job->queue === 'default',
        );
        Queue::assertPushed(
            ProcessMediaVideoIngestionJob::class,
            fn (ProcessMediaVideoIngestionJob $job): bool =>
                $job->mediaAssetId === (int) $video->getKey()
                && $job->queue === QueueContract::MEDIA_PROCESSING
                && $job->connection === 'redis-media',
        );

        $this->assertSame(['images' => 0, 'videos' => 0], $action->handle());
        Queue::assertPushed(GenerateMediaImageVariantsJob::class, 1);
        Queue::assertPushed(ProcessMediaVideoIngestionJob::class, 1);
        Storage::disk('spaces')->assertExists((string) $image->path);
        Storage::disk('spaces')->assertExists((string) $video->path);
        $this->assertNotNull($image->fresh()->image_variants_recovery_dispatched_at);
        $this->assertNotNull($video->fresh()->video_ingestion_recovery_dispatched_at);
    }

    public function test_a_lost_recovery_dispatch_can_be_retried_after_its_mysql_claim_expires(): void
    {
        $this->oldImage();
        $this->oldVideo();
        $action = app(RecoverLostMediaProcessingJobsAction::class);

        $this->assertSame(['images' => 1, 'videos' => 1], $action->handle());

        $this->travel(119)->minutes();
        $this->assertSame(['images' => 1, 'videos' => 0], $action->handle());
        Queue::assertPushed(ProcessMediaVideoIngestionJob::class, 1);

        $this->travel(2)->minutes();
        $this->assertSame(['images' => 0, 'videos' => 1], $action->handle());
        Queue::assertPushed(ProcessMediaVideoIngestionJob::class, 2);
    }

    public function test_recent_complete_failed_and_archived_assets_are_never_restarted(): void
    {
        $completeImage = $this->oldImage([
            'meta' => [
                'image_variants' => [
                    'version' => 1,
                    'files' => [
                        'medium' => ['path' => 'media/complete/medium.webp'],
                        'default' => ['path' => 'media/complete/default.webp'],
                    ],
                ],
            ],
        ]);
        $this->assertTrue($completeImage->hasProgressiveImageVariants());

        $this->oldImage(['archived_at' => now()->subHours(2)]);
        $this->oldVideo(['ingestion_status' => MediaAsset::INGESTION_READY]);
        $this->oldVideo(['ingestion_status' => MediaAsset::INGESTION_FAILED]);
        $this->oldVideo(['archived_at' => now()->subHours(2)]);

        MediaAsset::factory()->create([
            'kind' => MediaAsset::KIND_IMAGE,
            'disk' => 'spaces',
            'ingestion_status' => MediaAsset::INGESTION_READY,
            'meta' => null,
        ]);
        MediaAsset::factory()->video()->create([
            'disk' => 'spaces',
            'ingestion_status' => MediaAsset::INGESTION_PROCESSING,
        ]);

        $this->assertSame(
            ['images' => 0, 'videos' => 0],
            app(RecoverLostMediaProcessingJobsAction::class)->handle(),
        );
        Queue::assertNothingPushed();
    }

    public function test_disabled_media_processing_does_not_claim_or_dispatch_work(): void
    {
        $image = $this->oldImage();
        $video = $this->oldVideo();
        config()->set('media.image_variants.enabled', false);
        config()->set('media.video_ingestion.enabled', false);

        $this->assertSame(
            ['images' => 0, 'videos' => 0],
            app(RecoverLostMediaProcessingJobsAction::class)->handle(),
        );

        $this->assertNull($image->fresh()->image_variants_recovery_dispatched_at);
        $this->assertNull($video->fresh()->video_ingestion_recovery_dispatched_at);
        Queue::assertNothingPushed();
    }

    private function oldImage(array $overrides = []): MediaAsset
    {
        return MediaAsset::factory()->create([
            'disk' => 'spaces',
            'kind' => MediaAsset::KIND_IMAGE,
            'ingestion_status' => MediaAsset::INGESTION_READY,
            'meta' => null,
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
            ...$overrides,
        ]);
    }

    private function oldVideo(array $overrides = []): MediaAsset
    {
        return MediaAsset::factory()->video()->create([
            'disk' => 'spaces',
            'kind' => MediaAsset::KIND_VIDEO,
            'ingestion_status' => MediaAsset::INGESTION_PROCESSING,
            'path' => 'media-ingest/'.uniqid('test-video-', true).'/source.mov',
            'created_at' => now()->subHours(3),
            'updated_at' => now()->subHours(3),
            ...$overrides,
        ]);
    }
}
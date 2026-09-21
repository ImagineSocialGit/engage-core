<?php

namespace Tests\Feature\Media;

use App\Models\User;
use App\Modules\Media\Actions\StoreMediaAssetAction;
use App\Modules\Media\Jobs\ProcessMediaVideoIngestionJob;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Media\Services\MediaVideoIngestor;
use App\Support\Queues\QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MediaVideoIngestionTest extends TestCase
{
    use RefreshDatabase;

    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! app(MediaVideoIngestor::class)->available()) {
            $this->markTestSkipped('FFmpeg and FFprobe are required for the video-ingestion integration test.');
        }

        config()->set('modules.enabled', ['media']);
        config()->set('media.disk', 'spaces');
        config()->set('media.video_ingestion.enabled', true);
        config()->set('media.video_ingestion.connection', 'redis-media');
        config()->set('filesystems.disks.spaces', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/media-video-ingestion'),
            'url' => 'https://cdn.example.test',
        ]);
        Storage::fake('spaces', ['url' => 'https://cdn.example.test']);
        config()->set('filesystems.disks.spaces.url', 'https://cdn.example.test');

        $temporary = tempnam(sys_get_temp_dir(), 'media-ingestion-source-');
        $this->assertIsString($temporary);
        @unlink($temporary);

        $this->sourcePath = $temporary.'.mov';
        $this->generateSourceVideo($this->sourcePath);
    }

    protected function tearDown(): void
    {
        if (isset($this->sourcePath) && is_file($this->sourcePath)) {
            @unlink($this->sourcePath);
        }

        parent::tearDown();
    }

    public function test_video_upload_is_deduplicated_by_source_sha_then_normalized_once_before_it_becomes_ready(): void
    {
        Queue::fake();

        $sourceChecksum = hash_file('sha256', $this->sourcePath);
        $this->assertIsString($sourceChecksum);

        $action = app(StoreMediaAssetAction::class);
        $first = $action->handle(
            file: $this->upload(),
            title: 'Ingestion test',
        );

        $this->assertTrue($first->isProcessing());
        $this->assertStringStartsWith('media-ingest/', (string) $first->path);
        $this->assertNull($first->publicUrl());

        $sourceObjectPath = (string) $first->path;
        Storage::disk('spaces')->assertExists($sourceObjectPath);

        $second = $action->handle(
            file: $this->upload(),
            title: 'Duplicate title',
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, MediaAsset::query()->count());

        Queue::assertPushed(
            ProcessMediaVideoIngestionJob::class,
            1,
        );
        Queue::assertPushed(
            ProcessMediaVideoIngestionJob::class,
            fn (ProcessMediaVideoIngestionJob $job): bool =>
                $job->mediaAssetId === $first->getKey()
                && $job->queue === QueueContract::MEDIA_PROCESSING
                && $job->connection === 'redis-media',
        );

        (new ProcessMediaVideoIngestionJob((int) $first->getKey()))
            ->handle(app(MediaVideoIngestor::class));

        $asset = $first->fresh();

        $this->assertTrue($asset->isReady());
        $this->assertSame('video/mp4', $asset->mime_type);
        $this->assertSame('mp4', $asset->extension);
        $this->assertSame($sourceChecksum, $asset->checksum_sha256);
        $this->assertStringEndsWith('/'.$asset->uuid.'.mp4', (string) $asset->path);
        $this->assertNotNull($asset->ingested_at);
        $this->assertSame('transcode', data_get($asset->meta, 'video_ingestion.mode'));

        Storage::disk('spaces')->assertMissing($sourceObjectPath);
        Storage::disk('spaces')->assertExists((string) $asset->path);

        $posterPath = data_get($asset->meta, 'video_poster.path');
        $this->assertIsString($posterPath);
        Storage::disk('spaces')->assertExists($posterPath);

        $probe = $this->probe(Storage::disk('spaces')->path((string) $asset->path));
        $video = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'video');
        $audio = collect($probe['streams'] ?? [])->firstWhere('codec_type', 'audio');

        $this->assertSame('h264', $video['codec_name'] ?? null);
        $this->assertSame('yuv420p', $video['pix_fmt'] ?? null);
        $this->assertSame('aac', $audio['codec_name'] ?? null);
    }


    public function test_message_authoring_video_upload_enters_media_ingestion_and_exposes_pollable_status(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('crm.media.authoring.upload'), [
                'title' => 'Editor video',
                'file' => $this->upload(),
            ], [
                'HTTP_ACCEPT' => 'application/json',
            ]);

        $response
            ->assertStatus(202)
            ->assertJsonPath('kind', MediaAsset::KIND_VIDEO)
            ->assertJsonPath('ingestion_status', MediaAsset::INGESTION_PROCESSING)
            ->assertJsonPath('ready', false)
            ->assertJsonPath('failed', false);

        $asset = MediaAsset::query()->sole();

        $this->assertSame($asset->uuid, $response->json('asset_uuid'));
        $this->assertSame(
            route('crm.media.authoring.status', ['assetUuid' => $asset->uuid]),
            $response->json('status_url'),
        );

        $this->actingAs($user)
            ->getJson((string) $response->json('status_url'))
            ->assertOk()
            ->assertJsonPath('asset_uuid', $asset->uuid)
            ->assertJsonPath('ingestion_status', MediaAsset::INGESTION_PROCESSING)
            ->assertJsonPath('ready', false);

        Queue::assertPushed(
            ProcessMediaVideoIngestionJob::class,
            fn (ProcessMediaVideoIngestionJob $job): bool =>
                $job->mediaAssetId === $asset->getKey()
                && $job->queue === QueueContract::MEDIA_PROCESSING
                && $job->connection === 'redis-media',
        );
    }

    private function upload(): UploadedFile
    {
        return new UploadedFile(
            path: $this->sourcePath,
            originalName: 'source.mov',
            mimeType: 'video/quicktime',
            error: null,
            test: true,
        );
    }

    private function generateSourceVideo(string $path): void
    {
        $process = new Process([
            'ffmpeg',
            '-nostdin',
            '-hide_banner',
            '-loglevel', 'error',
            '-y',
            '-f', 'lavfi',
            '-i', 'color=c=blue:s=320x240:d=1',
            '-f', 'lavfi',
            '-i', 'sine=frequency=1000:duration=1',
            '-c:v', 'mpeg4',
            '-c:a', 'aac',
            '-shortest',
            '-f', 'mov',
            $path,
        ]);
        $process->setTimeout(30);
        $process->mustRun();
    }

    /** @return array<string, mixed> */
    private function probe(string $path): array
    {
        $process = new Process([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'stream=codec_type,codec_name,pix_fmt',
            '-of', 'json',
            $path,
        ]);
        $process->setTimeout(15);
        $process->mustRun();

        $decoded = json_decode($process->getOutput(), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
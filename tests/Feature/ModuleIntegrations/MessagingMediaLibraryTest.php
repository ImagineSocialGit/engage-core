<?php

namespace Tests\Feature\ModuleIntegrations;

use App\Models\User;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Messaging\Support\MessageMediaPayload;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use App\Support\ModuleIntegrations\Messaging\Media\MediaMessageMediaLibrary;
use App\Support\ModuleIntegrations\Messaging\Media\MediaMessageAttachmentSource;
use App\Support\ModuleIntegrations\Messaging\UnavailableMessageMediaLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MessagingMediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_bridge_is_available_only_when_messaging_and_media_are_enabled(): void
    {
        config()->set('modules.enabled', ['messaging']);
        $this->app->forgetInstance(MessageMediaLibrary::class);

        $this->assertInstanceOf(
            UnavailableMessageMediaLibrary::class,
            app(MessageMediaLibrary::class),
        );

        config()->set('modules.enabled', ['messaging', 'media']);
        $this->app->forgetInstance(MessageMediaLibrary::class);

        $this->assertInstanceOf(
            MediaMessageMediaLibrary::class,
            app(MessageMediaLibrary::class),
        );
    }

    public function test_media_bridge_snapshots_ready_video_with_optional_poster(): void
    {
        $this->configureMedia();
        $actor = User::factory()->create();
        $library = app(MediaMessageMediaLibrary::class);
        $poster = $library->store(
            file: UploadedFile::fake()->image('greeting-poster.jpg', 1200, 675),
            title: 'Greeting poster',
            uploadedBy: $actor,
        );

        Storage::disk('spaces')->put('media/video/welcome.mp4', 'video');
        $asset = MediaAsset::factory()->video()->create([
            'uploaded_by_type' => $actor->getMorphClass(),
            'uploaded_by_id' => $actor->getKey(),
            'title' => 'Welcome greeting',
            'disk' => 'spaces',
            'path' => 'media/video/welcome.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'ingestion_status' => MediaAsset::INGESTION_READY,
        ]);

        $video = $library->snapshot(
            assetUuid: $asset->uuid,
            posterAssetUuid: $poster['asset_uuid'],
        );

        $this->assertSame('video', $video['kind']);
        $this->assertSame('Welcome greeting', $video['title']);
        $this->assertSame(MessageMediaPayload::TRACKING_KEY, $video['tracking_key']);
        $this->assertSame($poster['asset_uuid'], $video['poster_asset_uuid']);
        $this->assertSame(route('media.video.show', ['assetUuid' => $video['asset_uuid']]), $video['url']);
        $this->assertSame('https://cdn.example.test/media/video/welcome.mp4', $video['playback_url']);
        $this->assertStringStartsWith('https://cdn.example.test/', $video['poster_url']);
        $this->assertSame(2, MediaAsset::query()->count());
    }

    public function test_synchronous_media_store_rejects_video_until_media_ingestion_is_complete(): void
    {
        $this->configureMedia();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Video uploads must finish Media ingestion before Messaging can snapshot them.');

        app(MediaMessageMediaLibrary::class)->store(
            file: UploadedFile::fake()->create('welcome.mp4', 512, 'video/mp4'),
            title: 'Welcome greeting',
        );
    }

    public function test_media_bridge_reuses_exact_upload_content_across_different_filenames(): void
    {
        $this->configureMedia();
        $library = app(MediaMessageMediaLibrary::class);
        $contents = 'same messaging media bytes';

        $first = $library->store(
            file: UploadedFile::fake()->createWithContent(
                'first-name.txt',
                $contents,
            ),
            title: 'Original message asset',
        );

        $second = $library->store(
            file: UploadedFile::fake()->createWithContent(
                'renamed-copy.txt',
                $contents,
            ),
            title: 'Duplicate title should not create a second asset',
        );

        $this->assertSame($first['asset_uuid'], $second['asset_uuid']);
        $this->assertSame('Original message asset', $second['title']);
        $this->assertSame(1, MediaAsset::query()->count());
        $this->assertCount(1, Storage::disk('spaces')->allFiles());
    }

    public function test_archived_assets_leave_new_selection_but_existing_snapshot_remains_self_contained(): void
    {
        $this->configureMedia();
        Storage::disk('spaces')->put('media/video/welcome.mp4', 'video');

        $asset = MediaAsset::factory()->video()->create([
            'title' => 'Welcome greeting',
            'disk' => 'spaces',
            'path' => 'media/video/welcome.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'ingestion_status' => MediaAsset::INGESTION_READY,
        ]);
        $library = app(MediaMessageMediaLibrary::class);
        $snapshot = $library->snapshot($asset->uuid);

        $asset->forceFill(['archived_at' => now()])->save();

        $this->assertSame([], $library->selectableAssets());
        $this->assertSame('Welcome greeting', $snapshot['title']);
        $this->assertSame(route('media.video.show', ['assetUuid' => $snapshot['asset_uuid']]), $snapshot['url']);
        $this->assertSame('https://cdn.example.test/media/video/welcome.mp4', $snapshot['playback_url']);

        $this->expectException(RuntimeException::class);
        $library->snapshot($snapshot['asset_uuid']);
    }

    public function test_generated_system_artifacts_are_hidden_from_message_media_selection_but_remain_resolvable(): void
    {
        $this->configureMedia();
        Storage::disk('spaces')->put('media/selectable.jpg', 'selectable');
        Storage::disk('spaces')->put('media/generated.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $selectable = MediaAsset::factory()->create([
            'disk' => 'spaces',
            'path' => 'media/selectable.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'ingestion_status' => MediaAsset::INGESTION_READY,
        ]);
        $generated = MediaAsset::factory()->create([
            'disk' => 'spaces',
            'path' => 'media/generated.svg',
            'mime_type' => 'image/svg+xml',
            'extension' => 'svg',
            'ingestion_status' => MediaAsset::INGESTION_READY,
            'meta' => ['authoring_selectable' => false],
        ]);

        $library = app(MediaMessageMediaLibrary::class);

        $this->assertSame(
            [(string) $selectable->uuid],
            array_column($library->selectableAssets(), 'uuid'),
        );
        $this->assertSame(
            (string) $generated->uuid,
            $library->snapshot((string) $generated->uuid)['asset_uuid'],
        );
    }

    public function test_generated_system_artifacts_are_hidden_from_message_attachment_selection_but_remain_resolvable(): void
    {
        $this->configureMedia();
        Storage::disk('spaces')->put('media/selectable.pdf', 'selectable');
        Storage::disk('spaces')->put('media/generated.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $selectable = MediaAsset::factory()->create([
            'kind' => MediaAsset::KIND_DOCUMENT,
            'disk' => 'spaces',
            'path' => 'media/selectable.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 10,
            'ingestion_status' => MediaAsset::INGESTION_READY,
        ]);
        $generated = MediaAsset::factory()->create([
            'disk' => 'spaces',
            'path' => 'media/generated.svg',
            'mime_type' => 'image/svg+xml',
            'extension' => 'svg',
            'size_bytes' => 10,
            'ingestion_status' => MediaAsset::INGESTION_READY,
            'meta' => ['authoring_selectable' => false],
        ]);

        $source = app(MediaMessageAttachmentSource::class);

        $this->assertSame(
            [(string) $selectable->uuid],
            array_map(
                static fn ($attachment): string => $attachment->id,
                $source->selectable(),
            ),
        );
        $this->assertSame(
            (string) $generated->uuid,
            $source->find((string) $generated->uuid)?->id,
        );
    }

    public function test_generated_video_poster_is_available_to_later_message_snapshots(): void
    {
        $this->configureMedia();
        Storage::disk('spaces')->put('media/video/introduction.mp4', 'video');
        Storage::disk('spaces')->put('media/video/video-poster.jpg', 'poster');

        $asset = MediaAsset::factory()->video()->create([
            'disk' => 'spaces',
            'path' => 'media/video/introduction.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'ingestion_status' => MediaAsset::INGESTION_READY,
            'meta' => [
                'video_poster' => [
                    'version' => 1,
                    'path' => 'media/video/video-poster.jpg',
                ],
            ],
        ]);

        $updated = app(MediaMessageMediaLibrary::class)->snapshot($asset->uuid);

        $this->assertSame(route('media.video.show', ['assetUuid' => $asset->uuid]), $updated['url']);
        $this->assertSame('https://cdn.example.test/media/video/introduction.mp4', $updated['playback_url']);
        $this->assertSame(
            'https://cdn.example.test/media/video/video-poster.jpg',
            $updated['poster_url'],
        );
        $this->assertArrayNotHasKey('poster_asset_uuid', $updated);
    }

    private function configureMedia(): void
    {
        config()->set('modules.enabled', ['messaging', 'media']);
        config()->set('media.disk', 'spaces');
        config()->set('filesystems.disks.spaces', [
            'driver' => 's3',
            'key' => 'test',
            'secret' => 'test',
            'region' => 'nyc3',
            'bucket' => 'test-bucket',
            'endpoint' => 'https://nyc3.digitaloceanspaces.com',
            'url' => 'https://cdn.example.test',
        ]);
        Storage::fake('spaces', [
            'url' => 'https://cdn.example.test',
        ]);
        config()->set('filesystems.disks.spaces.url', 'https://cdn.example.test');
    }
}
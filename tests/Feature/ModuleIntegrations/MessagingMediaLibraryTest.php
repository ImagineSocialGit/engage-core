<?php

namespace Tests\Feature\ModuleIntegrations;

use App\Models\User;
use App\Modules\Media\Models\MediaAsset;
use App\Modules\Messaging\Support\MessageMediaPayload;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use App\Support\ModuleIntegrations\Messaging\Media\MediaMessageMediaLibrary;
use App\Support\ModuleIntegrations\Messaging\UnavailableMessageMediaLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_media_bridge_uploads_and_snapshots_video_with_optional_poster(): void
    {
        $this->configureMedia();
        $actor = User::factory()->create();
        $library = app(MediaMessageMediaLibrary::class);
        $poster = $library->store(
            file: UploadedFile::fake()->image('greeting-poster.jpg', 1200, 675),
            title: 'Greeting poster',
            uploadedBy: $actor,
        );
        $video = $library->store(
            file: UploadedFile::fake()->create('welcome.mp4', 512, 'video/mp4'),
            title: 'Welcome greeting',
            posterAssetUuid: $poster['asset_uuid'],
            uploadedBy: $actor,
        );

        $this->assertSame('video', $video['kind']);
        $this->assertSame('Welcome greeting', $video['title']);
        $this->assertSame(MessageMediaPayload::TRACKING_KEY, $video['tracking_key']);
        $this->assertSame($poster['asset_uuid'], $video['poster_asset_uuid']);
        $this->assertStringStartsWith('https://cdn.example.test/', $video['url']);
        $this->assertStringStartsWith('https://cdn.example.test/', $video['poster_url']);
        $this->assertSame(2, MediaAsset::query()->count());
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
        $library = app(MediaMessageMediaLibrary::class);
        $snapshot = $library->store(
            file: UploadedFile::fake()->create('welcome.mp4', 128, 'video/mp4'),
            title: 'Welcome greeting',
        );
        $asset = MediaAsset::query()->where('uuid', $snapshot['asset_uuid'])->firstOrFail();
        $asset->forceFill(['archived_at' => now()])->save();

        $this->assertSame([], $library->selectableAssets());
        $this->assertSame('Welcome greeting', $snapshot['title']);
        $this->assertStringStartsWith('https://cdn.example.test/', $snapshot['url']);

        $this->expectException(\RuntimeException::class);
        $library->snapshot($snapshot['asset_uuid']);
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
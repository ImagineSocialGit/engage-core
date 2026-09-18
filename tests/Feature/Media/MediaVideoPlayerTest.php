<?php

namespace Tests\Feature\Media;

use App\Modules\Media\Models\MediaAsset;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Support\ModuleIntegrations\Messaging\Media\MediaMessageMediaLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaVideoPlayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_video_email_card_opens_public_player_instead_of_file_download(): void
    {
        $this->configureMedia();
        $asset = $this->video();
        $snapshot = app(MediaMessageMediaLibrary::class)->snapshot($asset->uuid);

        $this->assertSame(route('media.video.show', ['assetUuid' => $asset->uuid]), $snapshot['url']);
        $this->assertStringContainsString('video-poster.jpg', $snapshot['poster_url']);

        $payload = EmailPayload::fromArray([
            'to' => 'viewer@example.test',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'generic',
            'message_type' => 'example',
            'subject' => 'Watch this',
            'body' => "Video below:\n{media}",
            'media' => $snapshot,
        ]);

        $this->assertStringContainsString($snapshot['url'], $payload->html());
        $this->assertStringContainsString('video-poster.jpg', $payload->html());
        $this->assertStringNotContainsString('<video', $payload->html());
        $this->assertStringContainsString($snapshot['url'], $payload->plainText());
        $this->assertStringNotContainsString('welcome.mp4', $payload->plainText());

        $response = $this->get($snapshot['url']);
        $response->assertOk()->assertSee('<video', false)->assertSee('welcome.mp4');
    }

    public function test_archived_video_keeps_existing_email_link_playable(): void
    {
        $this->configureMedia();
        $asset = $this->video();
        $asset->forceFill(['archived_at' => now()])->save();

        $this->get(route('media.video.show', ['assetUuid' => $asset->uuid]))->assertOk();
    }

    private function configureMedia(): void
    {
        config()->set('modules.enabled', ['messaging', 'media']);
        config()->set('media.disk', 'spaces');
        config()->set('media.video_posters.enabled', false);
        config()->set('filesystems.disks.spaces', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/media-video-player'),
            'url' => 'https://cdn.example.test',
        ]);
        Storage::fake('spaces', ['url' => 'https://cdn.example.test']);
        config()->set('filesystems.disks.spaces.url', 'https://cdn.example.test');
    }

    private function video(): MediaAsset
    {
        Storage::disk('spaces')->put('media/video/welcome.mp4', 'video');
        Storage::disk('spaces')->put('media/video/video-poster.jpg', 'poster');

        return MediaAsset::factory()->create([
            'kind' => MediaAsset::KIND_VIDEO,
            'disk' => 'spaces',
            'path' => 'media/video/welcome.mp4',
            'mime_type' => 'video/mp4',
            'visibility' => MediaAsset::VISIBILITY_PUBLIC,
            'meta' => [
                'video_poster' => [
                    'version' => 1,
                    'path' => 'media/video/video-poster.jpg',
                ],
            ],
        ]);
    }
}
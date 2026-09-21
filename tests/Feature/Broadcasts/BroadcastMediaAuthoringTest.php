<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Media\Models\MediaAsset;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BroadcastMediaAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['messaging', 'media', 'broadcasts']);
        config()->set('modules.modules.messaging.enabled', true);
        config()->set('modules.modules.media.enabled', true);
        config()->set('modules.modules.broadcasts.enabled', true);
        config()->set('media.disk', 'spaces');
        config()->set('filesystems.disks.spaces', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/broadcast-media'),
            'url' => 'https://cdn.example.test',
        ]);
        Storage::fake('spaces', ['url' => 'https://cdn.example.test']);
        config()->set('filesystems.disks.spaces.url', 'https://cdn.example.test');

        $this->app->forgetInstance(MessageMediaLibrary::class);
    }

    public function test_media_marker_is_valid_during_authoring_when_a_ready_asset_is_selected(): void
    {
        $user = User::factory()->create();
        $asset = $this->readyVideo();

        $response = $this->actingAs($user)->post(route('crm.broadcasts.store'), [
            'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
            'intent' => 'draft',
            'name' => 'Video update',
            'subject' => 'Watch this',
            'body' => "Opening.\n{media}\nClosing.",
            'recipient_filter_type' => 'all',
            'media_present' => '1',
            'media_asset_uuid' => $asset->uuid,
            'media_size' => 'full',
        ]);

        $response->assertSessionHasNoErrors();

        $broadcast = Broadcast::query()->firstOrFail();
        $this->assertSame($asset->uuid, data_get($broadcast->messagePayload(), 'media.asset_uuid'));
        $this->assertSame('full', data_get($broadcast->messagePayload(), 'media.display_size'));
        $this->assertStringContainsString('{media}', (string) data_get($broadcast->messagePayload(), 'body'));

    }

    public function test_media_marker_still_fails_when_no_media_will_exist_after_authoring(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('crm.broadcasts.store'), [
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
                'intent' => 'draft',
                'name' => 'Broken marker',
                'subject' => 'Missing media',
                'body' => "Opening.\n{media}\nClosing.",
                'recipient_filter_type' => 'all',
                'media_present' => '1',
            ]);

        $response->assertSessionHasErrors('body');
        $this->assertSame(0, Broadcast::query()->count());
    }

    public function test_update_keeps_media_marker_valid_when_existing_media_is_preserved(): void
    {
        $user = User::factory()->create();
        $asset = $this->readyVideo();

        $this->actingAs($user)->post(route('crm.broadcasts.store'), [
            'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
            'intent' => 'draft',
            'name' => 'Video update',
            'subject' => 'Watch this',
            'body' => "Opening.\n{media}\nClosing.",
            'recipient_filter_type' => 'all',
            'media_present' => '1',
            'media_asset_uuid' => $asset->uuid,
        ])->assertSessionHasNoErrors();

        $broadcast = Broadcast::query()->firstOrFail();

        $this->actingAs($user)
            ->patch(route('crm.broadcasts.update', $broadcast), [
                'name' => 'Updated video update',
                'subject' => 'Still watch this',
                'body' => "Opening updated.\n{media}\nClosing.",
                'recipient_filter_type' => 'all',
            ])
            ->assertSessionHasNoErrors();

        $broadcast->refresh();

        $this->assertSame(
            $asset->uuid,
            data_get($broadcast->messagePayload(), 'media.asset_uuid'),
        );
    }

    private function readyVideo(): MediaAsset
    {
        Storage::disk('spaces')->put('media/video/preview.mp4', 'video');
        Storage::disk('spaces')->put('media/video/video-poster.jpg', 'poster');

        return MediaAsset::factory()->video()->create([
            'title' => 'Preview video',
            'disk' => 'spaces',
            'path' => 'media/video/preview.mp4',
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
    }
}
<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\PublishMessageTemplatePresetOverrideAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Support\MessageMediaPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplateMediaVersioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_media_snapshot_is_versioned_preserved_by_non_media_edits_and_explicitly_removable(): void
    {
        $preset = MessageTemplatePreset::factory()->create([
            'payload' => [
                'subject' => 'Welcome',
                'body' => 'Original body',
            ],
        ]);
        $media = $this->media();
        $action = app(PublishMessageTemplatePresetOverrideAction::class);

        $first = $action->handle($preset, [
            'subject' => 'Welcome',
            'body' => 'Original body',
            'media' => $media,
        ]);

        $this->assertEquals($media, $first->version->payload()['media']);

        $second = $action->handle($preset->fresh(), [
            'subject' => 'Welcome',
            'body' => 'Updated ordinary copy',
        ]);

        $this->assertEquals($media, $second->version->payload()['media']);
        $this->assertEquals(
            $media,
            MessageTemplate::query()->where('key', $preset->key)->firstOrFail()->currentPayload()['media'],
        );

        $third = $action->handle($preset->fresh(), [
            'subject' => 'Welcome',
            'body' => 'Updated ordinary copy',
            'media' => null,
        ]);

        $this->assertArrayNotHasKey('media', $third->version->payload());
    }

    /** @return array<string, string> */
    private function media(): array
    {
        return [
            'asset_uuid' => '11111111-1111-4111-8111-111111111111',
            'kind' => 'video',
            'title' => 'Welcome greeting',
            'url' => 'https://cdn.example.test/welcome.mp4',
            'mime_type' => 'video/mp4',
            'tracking_key' => MessageMediaPayload::TRACKING_KEY,
        ];
    }
}
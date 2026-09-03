<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Services\MessageTemplateCompositionSchema;
use App\Modules\Messaging\Support\MessageMediaPayload;
use InvalidArgumentException;
use Tests\TestCase;

class MessageMediaPayloadValidationTest extends TestCase
{
    public function test_composition_schema_accepts_valid_resolved_media_snapshot(): void
    {
        $normalized = app(MessageTemplateCompositionSchema::class)->normalize(
            scopeType: MessageTemplateCompositionLayer::SCOPE_MESSAGE,
            channel: 'email',
            payload: ['media' => $this->media()],
            messageTemplateId: 123,
        );

        $this->assertSame($this->media(), $normalized['payload']['media']);
    }

    public function test_media_payload_rejects_non_http_destination_and_video_poster_on_non_video_kind(): void
    {
        $invalidUrl = $this->media();
        $invalidUrl['url'] = 'javascript:alert(1)';

        $this->assertNotSame([], MessageMediaPayload::validationErrors($invalidUrl));

        $invalidPoster = $this->media();
        $invalidPoster['kind'] = 'image';
        $invalidPoster['poster_asset_uuid'] = '22222222-2222-4222-8222-222222222222';
        $invalidPoster['poster_url'] = 'https://cdn.example.test/poster.jpg';

        $this->expectException(InvalidArgumentException::class);
        MessageMediaPayload::assertValid($invalidPoster);
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
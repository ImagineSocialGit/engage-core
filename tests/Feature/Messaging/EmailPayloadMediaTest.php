<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Support\MessageMediaPayload;
use Tests\TestCase;

class EmailPayloadMediaTest extends TestCase
{
    public function test_video_media_renders_email_safe_card_and_plain_text_link(): void
    {
        $payload = EmailPayload::fromArray([
            'to' => 'fan@example.test',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'artist_updates',
            'message_type' => 'welcome',
            'subject' => 'Welcome',
            'body' => "Thanks for joining.\n{media}\nSee you soon.",
            'media' => $this->videoMedia(),
        ]);

        $html = $payload->html();
        $plain = $payload->plainText();

        $this->assertStringContainsString('https://cdn.example.test/welcome-poster.jpg', $html);
        $this->assertStringContainsString('https://cdn.example.test/welcome.mp4', $html);
        $this->assertStringContainsString('Welcome from the band', $html);
        $this->assertStringNotContainsString('<video', strtolower($html));
        $this->assertStringNotContainsString('{media}', $html);
        $this->assertStringContainsString('Watch Welcome from the band:', $plain);
        $this->assertStringContainsString('https://cdn.example.test/welcome.mp4', $plain);
        $this->assertStringNotContainsString('{media}', $plain);
    }

    public function test_media_is_appended_when_body_does_not_choose_a_marker_position(): void
    {
        $payload = EmailPayload::fromArray([
            'to' => 'fan@example.test',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'artist_updates',
            'message_type' => 'welcome',
            'subject' => 'Welcome',
            'body' => 'Thanks for joining.',
            'media' => $this->videoMedia(),
        ]);

        $this->assertStringContainsString('Welcome from the band', $payload->html());
        $this->assertStringContainsString('Watch Welcome from the band:', $payload->plainText());
    }

    public function test_media_uses_existing_cta_tracking_pipeline_when_scheduled_message_id_is_present(): void
    {
        $payload = EmailPayload::fromArray([
            'to' => 'fan@example.test',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'artist_updates',
            'message_type' => 'welcome',
            'subject' => 'Welcome',
            'body' => 'Thanks for joining.',
            'media' => $this->videoMedia(),
            'meta' => [
                'delivery' => ['scheduled_message_id' => 123],
            ],
        ]);

        $resolved = $payload->devPayload()['media'];

        $this->assertSame(MessageMediaPayload::TRACKING_KEY, $resolved['tracking_key']);
        $this->assertNotSame('https://cdn.example.test/welcome.mp4', $resolved['url']);
        $this->assertStringContainsString('media_primary', urldecode($resolved['url']));
    }

    public function test_tracked_image_keeps_raw_cdn_url_as_image_source(): void
    {
        $rawUrl = 'https://cdn.example.test/welcome.jpg';
        $payload = EmailPayload::fromArray([
            'to' => 'fan@example.test',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'artist_updates',
            'message_type' => 'welcome',
            'subject' => 'Welcome',
            'body' => 'Thanks for joining.',
            'media' => [
                'asset_uuid' => '33333333-3333-4333-8333-333333333333',
                'kind' => 'image',
                'title' => 'Welcome image',
                'url' => $rawUrl,
                'mime_type' => 'image/jpeg',
                'tracking_key' => MessageMediaPayload::TRACKING_KEY,
            ],
            'meta' => [
                'delivery' => ['scheduled_message_id' => 123],
            ],
        ]);

        $html = $payload->html();
        $trackedUrl = $payload->devPayload()['media']['url'];

        $this->assertStringContainsString('src="'.$rawUrl.'"', $html);
        $this->assertStringContainsString('href="'.e($trackedUrl).'"', $html);
    }

    /** @return array<string, string> */
    private function videoMedia(): array
    {
        return [
            'asset_uuid' => '11111111-1111-4111-8111-111111111111',
            'kind' => 'video',
            'title' => 'Welcome from the band',
            'url' => 'https://cdn.example.test/welcome.mp4',
            'mime_type' => 'video/mp4',
            'poster_asset_uuid' => '22222222-2222-4222-8222-222222222222',
            'poster_url' => 'https://cdn.example.test/welcome-poster.jpg',
            'tracking_key' => MessageMediaPayload::TRACKING_KEY,
        ];
    }
}
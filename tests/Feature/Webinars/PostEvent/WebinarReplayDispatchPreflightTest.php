<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarPostEventMessageRecipientGate;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery\MockInterface;
use Tests\TestCase;

class WebinarReplayDispatchPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_message_is_denied_when_provider_recording_is_gone(): void
    {
        Config::set('webinars.post_event.review.required', false);

        [$message, $registration] = $this->replayMessage();

        $provider = $this->mock(WebinarProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getRecording')->once()->andReturnNull();
        });

        $this->mock(WebinarProviderManager::class, function (MockInterface $mock) use ($provider): void {
            $mock->shouldReceive('forWebinar')->once()->andReturn($provider);
        });

        $reason = app(WebinarPostEventMessageRecipientGate::class)->denialReason(
            recipient: $registration->contact,
            channel: 'email',
            context: ['scheduled_message' => $message],
        );

        $this->assertSame('webinar_recording_unavailable', $reason);
    }

    public function test_replay_message_refreshes_authoritative_recording_before_send(): void
    {
        Config::set('webinars.post_event.review.required', false);

        [$message, $registration] = $this->replayMessage();
        $registration->webinar->forceFill([
            'playback_url' => 'https://zoom.example.test/replay/stale',
        ])->save();

        $provider = $this->mock(WebinarProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getRecording')
                ->once()
                ->andReturn(new ProviderRecordingData(
                    playbackUrl: 'https://zoom.example.test/replay/current',
                    playbackPasscode: 'new-pass',
                ));
        });

        $this->mock(WebinarProviderManager::class, function (MockInterface $mock) use ($provider): void {
            $mock->shouldReceive('forWebinar')->once()->andReturn($provider);
        });

        $reason = app(WebinarPostEventMessageRecipientGate::class)->denialReason(
            recipient: $registration->contact,
            channel: 'email',
            context: ['scheduled_message' => $message],
        );

        $this->assertNull($reason);
        $this->assertSame(
            'https://zoom.example.test/replay/current',
            $registration->webinar->fresh()->playback_url,
        );
        $this->assertSame('new-pass', $registration->webinar->fresh()->playback_passcode);
    }

    public function test_required_review_blocks_replay_message_before_provider_lookup(): void
    {
        Config::set('webinars.post_event.review.required', true);

        [$message, $registration] = $this->replayMessage();

        $this->mock(WebinarProviderManager::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('forWebinar');
        });

        $reason = app(WebinarPostEventMessageRecipientGate::class)->denialReason(
            recipient: $registration->contact,
            channel: 'email',
            context: ['scheduled_message' => $message],
        );

        $this->assertSame('webinar_post_event_review_pending', $reason);
    }

    public function test_non_replay_message_does_not_trigger_provider_preflight(): void
    {
        Config::set('webinars.post_event.review.required', true);

        [$message, $registration] = $this->replayMessage('Thanks for attending.');

        $this->mock(WebinarProviderManager::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('forWebinar');
        });

        $reason = app(WebinarPostEventMessageRecipientGate::class)->denialReason(
            recipient: $registration->contact,
            channel: 'email',
            context: ['scheduled_message' => $message],
        );

        $this->assertNull($reason);
    }

    /** @return array{0: ScheduledMessage, 1: WebinarRegistration} */
    private function replayMessage(
        string $body = 'Watch the replay: {webinar_playback_url}',
    ): array {
        $registration = WebinarRegistration::factory()->create();
        $registration->load(['contact', 'webinar']);

        $version = new MessageTemplateVersion([
            'content' => ['body' => $body],
        ]);

        $message = new ScheduledMessage([
            'channel' => 'email',
            'message_type' => 'post_missed',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'payload' => ['to' => $registration->contact->email],
        ]);
        $message->setRelation('context', $registration);
        $message->setRelation('messageTemplateVersion', $version);

        return [$message, $registration];
    }
}
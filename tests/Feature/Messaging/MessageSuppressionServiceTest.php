<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\MessageSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageSuppressionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_suppresses_a_destination(): void
    {
        $service = app(MessageSuppressionService::class);

        $suppression = $service->suppress(
            channel: MessageChannel::Sms,
            destination: '+15551234567',
            reason: 'provider',
            provider: 'twilio',
            sourceEventId: 'SM123',
            meta: ['error_code' => '30007'],
        );

        $this->assertDatabaseHas('message_suppressions', [
            'id' => $suppression->id,
            'channel' => MessageChannel::Sms->value,
            'destination' => '+15551234567',
            'reason' => 'provider',
            'provider' => 'twilio',
            'source_event_id' => 'SM123',
            'released_at' => null,
        ]);

        $this->assertTrue($suppression->isActive());
        $this->assertFalse($suppression->isReleased());
        $this->assertSame(['error_code' => '30007'], $suppression->meta);
    }

    public function test_it_detects_active_suppressions(): void
    {
        $service = app(MessageSuppressionService::class);

        $service->suppress(
            channel: MessageChannel::Email,
            destination: 'person@example.com',
            reason: 'bounce',
            provider: 'resend',
        );

        $this->assertTrue(
            $service->isSuppressed(
                MessageChannel::Email,
                'person@example.com',
            )
        );

        $this->assertFalse(
            $service->isSuppressed(
                MessageChannel::Sms,
                'person@example.com',
            )
        );
    }

    public function test_suppression_is_channel_specific(): void
    {
        $service = app(MessageSuppressionService::class);

        $service->suppress(
            channel: MessageChannel::Sms,
            destination: '+15551234567',
            reason: 'provider',
            provider: 'twilio',
        );

        $this->assertTrue(
            $service->isSuppressed(
                MessageChannel::Sms,
                '+15551234567',
            )
        );

        $this->assertFalse(
            $service->isSuppressed(
                MessageChannel::Email,
                '+15551234567',
            )
        );
    }

    public function test_suppress_is_idempotent_for_active_destination(): void
    {
        $service = app(MessageSuppressionService::class);

        $first = $service->suppress(
            channel: MessageChannel::Email,
            destination: 'person@example.com',
            reason: 'bounce',
            provider: 'resend',
            sourceEventId: 'evt_1',
        );

        $second = $service->suppress(
            channel: MessageChannel::Email,
            destination: 'person@example.com',
            reason: 'complaint',
            provider: 'resend',
            sourceEventId: 'evt_2',
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1, MessageSuppression::query()->count());

        $this->assertDatabaseHas('message_suppressions', [
            'id' => $first->id,
            'reason' => 'bounce',
            'source_event_id' => 'evt_1',
            'released_at' => null,
        ]);
    }

    public function test_suppress_is_idempotent_for_same_source_event(): void
    {
        $service = app(MessageSuppressionService::class);

        $first = $service->suppress(
            channel: MessageChannel::Email,
            destination: 'person@example.com',
            reason: 'bounce',
            provider: 'resend',
            sourceEventId: 'evt_1',
        );

        $second = $service->suppress(
            channel: MessageChannel::Email,
            destination: 'person@example.com',
            reason: 'bounce',
            provider: 'resend',
            sourceEventId: 'evt_1',
        );

        $this->assertTrue($first->is($second));
        $this->assertSame(1, MessageSuppression::query()->count());
    }

    public function test_it_releases_an_active_suppression(): void
    {
        $service = app(MessageSuppressionService::class);

        $suppression = $service->suppress(
            channel: MessageChannel::Sms,
            destination: '+15551234567',
            reason: 'provider',
            provider: 'twilio',
            sourceEventId: 'SM_STOP',
        );

        $released = $service->release(
            channel: MessageChannel::Sms,
            destination: '+15551234567',
            provider: 'twilio',
            sourceEventId: 'SM_START',
            meta: [
                'body' => 'START',
            ],
        );

        $this->assertNotNull($released);

        $released->refresh();

        $this->assertTrue($released->isReleased());
        $this->assertNotNull($released->released_at);

        $this->assertSame('twilio', data_get($released->meta, 'release.provider'));
        $this->assertSame('SM_START', data_get($released->meta, 'release.source_event_id'));
        $this->assertSame(
            ['body' => 'START'],
            data_get($released->meta, 'release.meta')
        );

        $this->assertFalse(
            $service->isSuppressed(
                MessageChannel::Sms,
                '+15551234567',
            )
        );
    }

    public function test_release_returns_null_when_no_active_suppression_exists(): void
    {
        $service = app(MessageSuppressionService::class);

        $released = $service->release(
            channel: MessageChannel::Email,
            destination: 'missing@example.com',
        );

        $this->assertNull($released);
    }

    public function test_it_accepts_telnyx_as_a_suppression_provider(): void
    {
        $suppression = app(MessageSuppressionService::class)->suppress(
            channel: MessageChannel::Sms,
            destination: '+15555550123',
            reason: MessageSuppression::REASON_PROVIDER,
            provider: MessageSuppression::PROVIDER_TELNYX,
            sourceEventId: 'telnyx-event-1',
        );

        $this->assertSame(MessageSuppression::PROVIDER_TELNYX, $suppression->provider);
        $this->assertDatabaseHas('message_suppressions', [
            'id' => $suppression->getKey(),
            'provider' => MessageSuppression::PROVIDER_TELNYX,
            'source_event_id' => 'telnyx-event-1',
        ]);
    }
}


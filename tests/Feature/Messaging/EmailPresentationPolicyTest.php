<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\Email\EmailPresentationResolver;
use App\Modules\Messaging\Services\ScheduledMessagePayloadResolver;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class EmailPresentationPolicyTest extends TestCase
{
    public function test_scheduled_email_uses_configured_surface_presentation(): void
    {
        Config::set('messaging.email.presentation.default', EmailPayload::PRESENTATION_CLIENT);
        Config::set('messaging.email.presentation.surfaces', [
            'campaigns' => EmailPayload::PRESENTATION_STANDARD,
        ]);

        $message = $this->legacyEmail([
            'surface' => 'campaigns',
        ]);

        $payload = app(ScheduledMessagePayloadResolver::class)->resolve($message);

        $this->assertInstanceOf(EmailPayload::class, $payload);
        $this->assertSame(EmailPayload::PRESENTATION_STANDARD, $payload->presentation);
    }

    public function test_message_chain_surface_is_used_when_scheduled_message_has_no_surface_meta(): void
    {
        Config::set('messaging.email.presentation.default', EmailPayload::PRESENTATION_CLIENT);
        Config::set('messaging.email.presentation.surfaces', [
            'campaigns' => EmailPayload::PRESENTATION_STANDARD,
        ]);

        $message = $this->legacyEmail();
        $message->forceFill(['message_chain_enrollment_id' => 123]);
        $message->setRelation(
            'messageChainEnrollment',
            (new MessageChainEnrollment())->forceFill([
                'surface' => 'campaigns',
            ]),
        );

        $this->assertSame(
            EmailPayload::PRESENTATION_STANDARD,
            app(EmailPresentationResolver::class)->modeFor($message),
        );
    }

    public function test_unconfigured_surface_keeps_client_presentation(): void
    {
        Config::set('messaging.email.presentation.default', EmailPayload::PRESENTATION_CLIENT);
        Config::set('messaging.email.presentation.surfaces', [
            'campaigns' => EmailPayload::PRESENTATION_STANDARD,
        ]);

        $message = $this->legacyEmail([
            'surface' => 'webinar_registrations',
        ]);

        $payload = app(ScheduledMessagePayloadResolver::class)->resolve($message);

        $this->assertInstanceOf(EmailPayload::class, $payload);
        $this->assertSame(EmailPayload::PRESENTATION_CLIENT, $payload->presentation);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function legacyEmail(array $meta = []): ScheduledMessage
    {
        return (new ScheduledMessage())->forceFill([
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'fixture',
            'message_type' => 'fixture',
            'payload_class' => EmailPayload::class,
            'payload' => [
                'to' => 'person@example.test',
                'subject' => 'Fixture subject',
                'body' => 'Fixture body.',
            ],
            'meta' => $meta,
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);
    }
}
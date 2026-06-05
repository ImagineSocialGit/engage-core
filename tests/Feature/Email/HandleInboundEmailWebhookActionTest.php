<?php

namespace Tests\Feature\Email;

use App\Actions\Messaging\Email\HandleInboundEmailWebhookAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\ConsentRevocation;
use App\Models\Contact;
use App\Models\MessageSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleInboundEmailWebhookActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_bounce_event_suppresses_email_destination(): void
    {
        app(HandleInboundEmailWebhookAction::class)->handle(
            event: $this->event(type: 'email.bounced', email: 'Person@Example.com'),
            sourceEventId: 'evt_bounce_1',
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this->assertDatabaseHas('message_suppressions', [
            'channel' => MessageChannel::Email->value,
            'destination' => 'person@example.com',
            'reason' => MessageSuppression::REASON_BOUNCE,
            'provider' => MessageSuppression::PROVIDER_RESEND,
            'source_event_id' => 'evt_bounce_1',
            'released_at' => null,
        ]);
    }

    public function test_complaint_event_suppresses_email_destination(): void
    {
        app(HandleInboundEmailWebhookAction::class)->handle(
            event: $this->event(type: 'email.complained'),
            sourceEventId: 'evt_complaint_1',
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this->assertDatabaseHas('message_suppressions', [
            'channel' => MessageChannel::Email->value,
            'destination' => 'person@example.com',
            'reason' => MessageSuppression::REASON_COMPLAINT,
            'provider' => MessageSuppression::PROVIDER_RESEND,
            'source_event_id' => 'evt_complaint_1',
            'released_at' => null,
        ]);
    }

    public function test_provider_suppressed_event_suppresses_email_destination(): void
    {
        app(HandleInboundEmailWebhookAction::class)->handle(
            event: $this->event(type: 'email.suppressed'),
            sourceEventId: 'evt_suppressed_1',
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this->assertDatabaseHas('message_suppressions', [
            'channel' => MessageChannel::Email->value,
            'destination' => 'person@example.com',
            'reason' => MessageSuppression::REASON_PROVIDER,
            'provider' => MessageSuppression::PROVIDER_RESEND,
            'source_event_id' => 'evt_suppressed_1',
            'released_at' => null,
        ]);
    }

    public function test_failed_event_with_invalid_destination_suppresses_email_destination(): void
    {
        app(HandleInboundEmailWebhookAction::class)->handle(
            event: $this->event(
                type: 'email.failed',
                data: [
                    'email_id' => 'email_123',
                    'to' => ['person@example.com'],
                    'reason' => 'Contact address is invalid',
                ],
            ),
            sourceEventId: 'evt_failed_invalid_1',
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this->assertDatabaseHas('message_suppressions', [
            'channel' => MessageChannel::Email->value,
            'destination' => 'person@example.com',
            'reason' => MessageSuppression::REASON_INVALID_DESTINATION,
            'provider' => MessageSuppression::PROVIDER_RESEND,
            'source_event_id' => 'evt_failed_invalid_1',
            'released_at' => null,
        ]);
    }

    public function test_failed_event_with_temporary_reason_does_not_suppress(): void
    {
        app(HandleInboundEmailWebhookAction::class)->handle(
            event: $this->event(
                type: 'email.failed',
                data: [
                    'email_id' => 'email_123',
                    'to' => ['person@example.com'],
                    'reason' => 'Temporary deferral from remote mail server',
                ],
            ),
            sourceEventId: 'evt_failed_temp_1',
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this->assertDatabaseCount('message_suppressions', 0);
    }

    public function test_unsubscribe_event_revokes_marketing_consent_without_suppression(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.com',
        ]);

        app(HandleInboundEmailWebhookAction::class)->handle(
            event: $this->event(type: 'email.unsubscribed'),
            sourceEventId: 'evt_unsubscribe_1',
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'reason' => ConsentRevocation::REASON_PROVIDER_UNSUBSCRIBE,
            'source' => 'resend_webhook',
        ]);

        $this->assertDatabaseCount('message_suppressions', 0);
    }

    public function test_unsubscribe_event_for_unknown_contact_does_not_create_revocation_or_suppression(): void
    {
        app(HandleInboundEmailWebhookAction::class)->handle(
            event: $this->event(type: 'email.unsubscribed'),
            sourceEventId: 'evt_unsubscribe_unknown_1',
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this->assertDatabaseCount('consent_revocations', 0);
        $this->assertDatabaseCount('message_suppressions', 0);
    }

    public function test_repeated_provider_event_is_idempotent(): void
    {
        $event = $this->event(type: 'email.bounced');

        app(HandleInboundEmailWebhookAction::class)->handle(
            event: $event,
            sourceEventId: 'evt_same_1',
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        app(HandleInboundEmailWebhookAction::class)->handle(
            event: $event,
            sourceEventId: 'evt_same_1',
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this->assertDatabaseCount('message_suppressions', 1);
    }

    public function test_unknown_event_is_ignored(): void
    {
        app(HandleInboundEmailWebhookAction::class)->handle(
            event: $this->event(type: 'email.delivered'),
            sourceEventId: 'evt_delivered_1',
            provider: MessageSuppression::PROVIDER_RESEND,
        );

        $this->assertDatabaseCount('message_suppressions', 0);
        $this->assertDatabaseCount('consent_revocations', 0);
    }

    private function event(
        string $type,
        string $email = 'person@example.com',
        ?array $data = null,
    ): array {
        return [
            'type' => $type,
            'created_at' => now()->toIso8601String(),
            'data' => $data ?? [
                'email_id' => 'email_123',
                'to' => [$email],
                'subject' => 'Test email',
            ],
        ];
    }
}
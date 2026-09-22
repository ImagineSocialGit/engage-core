<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Actions\Sms\ReconcileInboundSmsHistoryAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\Consent\MessageConsentStateResolver;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InboundSmsHistoryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconciliation_links_and_correlates_historical_sms_without_replaying_normal_reply_automation(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '6155551212',
        ]);
        $scheduled = ScheduledMessage::factory()
            ->forContact($contact)
            ->sms()
            ->sent()
            ->create([
                'purpose' => 'marketing',
                'scope' => 'mortgage_homebuyer_nurture',
                'send_at' => now()->subHours(2),
            ]);
        $scheduled->latestDeliveryAttempt()->update([
            'destination' => '6155551212',
        ]);
        $message = InboundMessage::query()->create([
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'historical-reply-event',
            'provider_message_id' => 'historical-reply-message',
            'from_type' => 'phone',
            'from_value' => '+16155551212',
            'to_type' => 'phone',
            'to_value' => '+16155559999',
            'body' => 'Yes, please call me.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now()->subHour(),
            'inbox_status' => InboundMessage::INBOX_STATUS_NEW,
        ]);

        $result = app(ReconcileInboundSmsHistoryAction::class)->handle();
        $message->refresh();

        $this->assertSame(1, $result['linked']);
        $this->assertSame(1, $result['replies_correlated']);
        $this->assertSame($contact->getMorphClass(), $message->sender_type);
        $this->assertSame($contact->getKey(), $message->sender_id);
        $this->assertSame($scheduled->getKey(), $message->correlated_scheduled_message_id);
        $this->assertSame('marketing', $message->purpose?->value);
        $this->assertSame('mortgage_homebuyer_nurture', $message->scope);
        $this->assertSame('heuristic', $message->reply_correlation_method);
        $this->assertDatabaseCount('automation_event_outbox_events', 0);
    }

    public function test_historical_stop_repair_uses_original_received_time_and_does_not_override_a_later_reopt_in(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '6155551212',
        ]);
        $stopAt = Carbon::parse('2026-09-18 15:00:00', 'UTC');
        $laterConsentAt = $stopAt->copy()->addDay();

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => $stopAt->copy()->subDay(),
            'source' => 'test',
        ]);
        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => $laterConsentAt,
            'source' => 'later_reopt_in',
        ]);

        $message = InboundMessage::query()->create([
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'historical-stop-event',
            'provider_message_id' => 'historical-stop-message',
            'from_type' => 'phone',
            'from_value' => '+16155551212',
            'to_type' => 'phone',
            'to_value' => '+16155559999',
            'body' => 'STOP',
            'classification' => InboundMessage::CLASSIFICATION_CONSENT_REVOCATION,
            'purpose' => 'marketing',
            'received_at' => $stopAt,
            'inbox_status' => InboundMessage::INBOX_STATUS_DONE,
            'completed_at' => $stopAt,
        ]);

        $result = app(ReconcileInboundSmsHistoryAction::class)->handle();
        $message->refresh();
        $revocation = ConsentRevocation::query()->sole();

        $this->assertSame(1, $result['stop_messages_processed']);
        $this->assertSame(1, $result['stop_revocations_created']);
        $this->assertSame($contact->getKey(), $message->sender_id);
        $this->assertNotNull($message->processed_at);
        $this->assertTrue($revocation->revoked_at->equalTo($stopAt));
        $this->assertSame(
            $message->getKey(),
            data_get($revocation->meta, 'inbound_message_id'),
        );
        $this->assertTrue(
            app(MessageConsentStateResolver::class)->isActive(
                contact: $contact,
                channel: 'sms',
                purpose: 'marketing',
            ),
        );
    }

    public function test_reconciliation_refuses_ambiguous_phone_identity_and_manual_link_conflicts(): void
    {
        $first = Contact::factory()->create([
            'phone' => '6155551212',
        ]);
        Contact::factory()->create([
            'phone' => '+16155551212',
        ]);
        $manual = Contact::factory()->create([
            'phone' => '6155551313',
        ]);

        $ambiguous = InboundMessage::query()->create([
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'ambiguous-event',
            'from_type' => 'phone',
            'from_value' => '+16155551212',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now()->subHour(),
        ]);
        $conflicting = InboundMessage::query()->create([
            'related_contact_id' => $manual->getKey(),
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'manual-conflict-event',
            'from_type' => 'phone',
            'from_value' => '+16155551313',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now()->subHour(),
        ]);

        $other = Contact::factory()->create([
            'phone' => '+16155551313',
        ]);
        $manual->forceFill(['phone' => '6155551414'])->saveQuietly();

        $result = app(ReconcileInboundSmsHistoryAction::class)->handle();

        $this->assertGreaterThanOrEqual(1, $result['unresolved']);
        $this->assertSame(1, $result['manual_link_conflicts']);
        $this->assertNull($ambiguous->refresh()->sender_id);
        $this->assertNull($conflicting->refresh()->sender_id);
        $this->assertSame($manual->getKey(), $conflicting->related_contact_id);
        $this->assertNotSame($manual->getKey(), $other->getKey());
        $this->assertNotSame($first->getKey(), $other->getKey());
    }
}
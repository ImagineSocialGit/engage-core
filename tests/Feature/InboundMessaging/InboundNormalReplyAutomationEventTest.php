<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use App\Support\AutomationOpportunities\Actions\RecordAutomationEventCorrelationEvidenceAction;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundNormalReplyAutomationEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_normal_reply_for_contact_becomes_automation_event_correlation_evidence(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);

        $receivedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');

        $inboundMessage = app(RecordInboundMessageAction::class)->handle(
            data: [
                'channel' => 'sms',
                'provider' => 'telnyx',
                'provider_event_id' => 'evt_test',
                'provider_message_id' => 'msg_test',
                'provider_context_id' => 'profile_test',
                'from_type' => 'phone',
                'from_value' => '+15551234567',
                'to_type' => 'phone',
                'to_value' => '+15550001111',
                'body' => 'I am interested',
                'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
                'purpose' => 'marketing',
                'scope' => null,
                'received_at' => $receivedAt,
                'meta' => [
                    'source' => 'telnyx_inbound_sms',
                ],
            ],
            sender: $contact,
        );

        $outboxEvent = AutomationEventOutboxEvent::query()
            ->where('event_key', RecordInboundMessageAction::NORMAL_REPLY_AUTOMATION_EVENT_KEY)
            ->firstOrFail();

        if ($outboxEvent->status !== AutomationEventOutboxEvent::STATUS_PUBLISHED) {
            $this->assertTrue(
                app(AutomationEventOutbox::class)->publish((int) $outboxEvent->getKey()),
            );
        }

        $occurrence = AutomationBehaviorOccurrence::query()
            ->forAction(RecordAutomationEventCorrelationEvidenceAction::ACTION_KEY)
            ->firstOrFail();

        $this->assertSame($contact->getMorphClass(), $occurrence->subject_type);
        $this->assertSame($contact->getKey(), $occurrence->subject_id);
        $this->assertSame(
            RecordInboundMessageAction::NORMAL_REPLY_AUTOMATION_EVENT_KEY,
            $occurrence->fingerprint_parts['event_key'],
        );
        $this->assertSame(
            RecordInboundMessageAction::NORMAL_REPLY_AUTOMATION_EVENT_KEY,
            $occurrence->context['event_key'],
        );
        $this->assertSame(
            $inboundMessage->getMorphClass(),
            $occurrence->context['automation_event_subject_type'],
        );
        $this->assertSame(
            $inboundMessage->getKey(),
            $occurrence->context['automation_event_subject_id'],
        );
        $this->assertSame('inbound_messaging', $occurrence->context['source_module']);
        $this->assertSame('inbound_message_received', $occurrence->context['source']);
        $this->assertTrue($occurrence->occurred_at->equalTo($receivedAt));

        $this->assertDatabaseCount('automation_opportunities', 0);
    }

    public function test_help_message_does_not_become_automation_event_correlation_evidence(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);

        app(RecordInboundMessageAction::class)->handle(
            data: [
                'channel' => 'sms',
                'provider' => 'telnyx',
                'from_type' => 'phone',
                'from_value' => '+15551234567',
                'to_type' => 'phone',
                'to_value' => '+15550001111',
                'body' => 'HELP',
                'classification' => InboundMessage::CLASSIFICATION_HELP,
                'purpose' => 'transactional',
                'received_at' => now(),
            ],
            sender: $contact,
        );

        $this->assertDatabaseCount('automation_behavior_occurrences', 0);
        $this->assertDatabaseCount('automation_opportunities', 0);
    }

    public function test_normal_reply_without_contact_does_not_become_correlation_evidence(): void
    {
        app(RecordInboundMessageAction::class)->handle(
            data: [
                'channel' => 'sms',
                'provider' => 'telnyx',
                'from_type' => 'phone',
                'from_value' => '+15551234567',
                'to_type' => 'phone',
                'to_value' => '+15550001111',
                'body' => 'Can someone call me?',
                'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
                'purpose' => 'marketing',
                'received_at' => now(),
            ],
        );

        $this->assertDatabaseCount('automation_behavior_occurrences', 0);
        $this->assertDatabaseCount('automation_opportunities', 0);
    }
}
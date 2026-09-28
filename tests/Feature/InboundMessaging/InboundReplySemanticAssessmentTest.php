<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Contracts\ReplySemanticAssessmentProvider;
use App\Modules\InboundMessaging\Data\ReplySemanticAssessment;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundReplySemanticAssessmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_deterministic_assessment_is_conservative_about_current_intent(): void
    {
        $provider = app(ReplySemanticAssessmentProvider::class);

        $cases = [
            [
                'body' => "I'm interested right now. Can we talk later this week?",
                'category' => ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
                'interest' => ReplySemanticAssessment::INTEREST_POSITIVE,
                'readiness' => ReplySemanticAssessment::READINESS_NOW,
                'action' => ReplySemanticAssessment::ACTION_CALL,
            ],
            [
                'body' => "I'm interested, but I'm not available this week.",
                'category' => ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
                'interest' => ReplySemanticAssessment::INTEREST_POSITIVE,
                'readiness' => ReplySemanticAssessment::READINESS_NEAR_TERM,
                'action' => ReplySemanticAssessment::ACTION_NONE,
                'constraint' => ReplySemanticAssessment::CONSTRAINT_SCHEDULING,
            ],
            [
                'body' => "I'm not interested right now, but I'll let you know later.",
                'category' => ReplySemanticAssessment::CATEGORY_POSITIVE_DEFERRED,
                'interest' => ReplySemanticAssessment::INTEREST_UNCLEAR,
                'readiness' => ReplySemanticAssessment::READINESS_LATER,
                'action' => ReplySemanticAssessment::ACTION_NONE,
            ],
            [
                'body' => "Thank you so much for reaching out! I'd love to, but I'm not ready yet.",
                'category' => ReplySemanticAssessment::CATEGORY_POSITIVE_DEFERRED,
                'interest' => ReplySemanticAssessment::INTEREST_POSITIVE,
                'readiness' => ReplySemanticAssessment::READINESS_NOT_READY,
                'action' => ReplySemanticAssessment::ACTION_NONE,
            ],
            [
                'body' => 'No thank you. I am not interested.',
                'category' => ReplySemanticAssessment::CATEGORY_NEGATIVE,
                'interest' => ReplySemanticAssessment::INTEREST_NEGATIVE,
                'readiness' => ReplySemanticAssessment::READINESS_UNKNOWN,
                'action' => ReplySemanticAssessment::ACTION_NONE,
            ],
            [
                'body' => 'Thanks!',
                'category' => ReplySemanticAssessment::CATEGORY_ROUTINE,
                'interest' => ReplySemanticAssessment::INTEREST_NEUTRAL,
                'readiness' => ReplySemanticAssessment::READINESS_UNKNOWN,
                'action' => ReplySemanticAssessment::ACTION_NONE,
            ],
            [
                'body' => 'That is interesting. I need to think through a few things.',
                'category' => ReplySemanticAssessment::CATEGORY_NEEDS_REVIEW,
                'interest' => ReplySemanticAssessment::INTEREST_UNCLEAR,
                'readiness' => ReplySemanticAssessment::READINESS_UNKNOWN,
                'action' => ReplySemanticAssessment::ACTION_NONE,
            ],
        ];

        foreach ($cases as $case) {
            $assessment = $provider->assess($case['body']);

            $this->assertSame($case['category'], $assessment->category);
            $this->assertSame($case['interest'], $assessment->interest);
            $this->assertSame($case['readiness'], $assessment->readiness);
            $this->assertSame($case['action'], $assessment->requestedAction);

            if (isset($case['constraint'])) {
                $this->assertSame($case['constraint'], $assessment->constraint);
            }
        }
    }

    public function test_recorded_normal_reply_persists_semantic_evidence_and_exposes_it_to_automation(): void
    {
        $contact = Contact::factory()->create();

        $message = app(RecordInboundMessageAction::class)->handle(
            data: [
                'channel' => 'sms',
                'provider' => 'test-provider',
                'provider_event_id' => 'evt-semantic-high-intent',
                'provider_message_id' => 'msg-semantic-high-intent',
                'from_type' => 'phone',
                'from_value' => '+13125550123',
                'to_type' => 'phone',
                'to_value' => '+13125550999',
                'body' => "I'm interested, but I'm not available this week.",
                'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
                'received_at' => now(),
            ],
            sender: $contact,
        )->refresh();

        $this->assertSame(
            ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
            $message->reply_semantic_category,
        );
        $this->assertSame(
            ReplySemanticAssessment::INTEREST_POSITIVE,
            $message->reply_semantic_interest,
        );
        $this->assertSame(
            ReplySemanticAssessment::READINESS_NEAR_TERM,
            $message->reply_semantic_readiness,
        );
        $this->assertSame(
            ReplySemanticAssessment::CONSTRAINT_SCHEDULING,
            $message->reply_semantic_constraint,
        );
        $this->assertSame('deterministic_v1', $message->reply_semantic_source);
        $this->assertNotNull($message->reply_semantic_assessed_at);

        $event = AutomationEventOutboxEvent::query()
            ->where(
                'event_key',
                RecordInboundMessageAction::NORMAL_REPLY_AUTOMATION_EVENT_KEY,
            )
            ->sole();

        $this->assertSame(
            ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
            data_get(
                $event->payload,
                'inbound_message.semantic_assessment.category',
            ),
        );
        $this->assertSame(
            ReplySemanticAssessment::CONSTRAINT_SCHEDULING,
            data_get(
                $event->payload,
                'inbound_message.semantic_assessment.constraint',
            ),
        );
    }

    public function test_ambiguous_normal_reply_is_persisted_as_needing_review(): void
    {
        $message = app(RecordInboundMessageAction::class)->handle([
            'channel' => 'email',
            'provider' => 'test-provider',
            'provider_event_id' => 'evt-semantic-review',
            'provider_message_id' => 'msg-semantic-review',
            'from_type' => 'email',
            'from_value' => 'person@example.test',
            'to_type' => 'email',
            'to_value' => 'reply@example.test',
            'body' => 'That is interesting. I need to think through a few things.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now(),
        ])->refresh();

        $this->assertSame(
            ReplySemanticAssessment::CATEGORY_NEEDS_REVIEW,
            $message->reply_semantic_category,
        );
        $this->assertSame(
            ReplySemanticAssessment::CONFIDENCE_LOW,
            $message->reply_semantic_confidence,
        );
        $this->assertSame(
            'no_confident_rule',
            $message->reply_semantic_rule_key,
        );
    }

    public function test_routed_intake_and_non_reply_classifications_are_not_semantically_assessed(): void
    {
        $routed = app(RecordInboundMessageAction::class)->handle([
            'channel' => 'email',
            'provider' => 'test-provider',
            'provider_event_id' => 'evt-semantic-routed',
            'provider_message_id' => 'msg-semantic-routed',
            'from_type' => 'email',
            'from_value' => 'vendor@example.test',
            'to_type' => 'email',
            'to_value' => 'vendor-updates@example.test',
            'body' => 'I am interested and ready to move forward.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'inbound_email_route_key' => 'vendor_updates',
            'inbound_email_route_source' => 'integration',
            'inbound_email_route_context' => 'vendor_update',
            'received_at' => now(),
        ])->refresh();

        $help = app(RecordInboundMessageAction::class)->handle([
            'channel' => 'sms',
            'provider' => 'test-provider',
            'provider_event_id' => 'evt-semantic-help',
            'provider_message_id' => 'msg-semantic-help',
            'from_type' => 'phone',
            'from_value' => '+13125550124',
            'to_type' => 'phone',
            'to_value' => '+13125550999',
            'body' => 'HELP',
            'classification' => InboundMessage::CLASSIFICATION_HELP,
            'received_at' => now(),
        ])->refresh();

        foreach ([$routed, $help] as $message) {
            $this->assertNull($message->reply_semantic_category);
            $this->assertNull($message->reply_semantic_source);
            $this->assertNull($message->reply_semantic_assessed_at);
        }
    }
}
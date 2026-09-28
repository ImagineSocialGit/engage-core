<?php

namespace Tests\Feature\Reporting;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Data\ReplySemanticAssessment;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\ModuleIntegrations\Reporting\DailyFollowUp\DailyFollowUpReportBuilder;
use App\Support\ModuleIntegrations\Reporting\DailyFollowUp\DailyFollowUpScheduledReportProvider;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyFollowUpScheduledReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.timezone', 'America/Chicago');
    }

    public function test_new_daily_follow_up_reports_default_to_reply_only(): void
    {
        $parameters = app(DailyFollowUpScheduledReportProvider::class)
            ->defaultParameters();

        $this->assertTrue($parameters['include_replies']);
        $this->assertFalse($parameters['include_tasks']);
        $this->assertFalse($parameters['include_appointments']);
        $this->assertSame([], $parameters['new_lead_status_keys']);
        $this->assertSame([], $parameters['incomplete_application_status_keys']);
        $this->assertSame([], $parameters['next_action_status_keys']);
        $this->assertSame([], $parameters['follow_up_status_days']);
    }

    public function test_reply_section_prioritizes_semantic_assessment_without_hiding_unresolved_replies(): void
    {
        Carbon::setTestNow('2026-09-28 18:30:00 UTC');

        $routineContact = Contact::factory()->create([
            'first_name' => 'Alex',
            'last_name' => 'Buyer',
            'name' => null,
        ]);
        $highIntentContact = Contact::factory()->create([
            'first_name' => 'Jordan',
            'last_name' => 'Realtor',
            'name' => null,
        ]);

        $routineReply = $this->reply($routineContact, [
            'channel' => 'sms',
            'body' => 'Thanks, I received it.',
            'reply_semantic_category' =>
                ReplySemanticAssessment::CATEGORY_ROUTINE,
            'inbox_status' => InboundMessage::INBOX_STATUS_NEW,
            'received_at' => now()->subMinutes(10),
        ]);
        $highIntentReply = $this->reply($highIntentContact, [
            'channel' => 'email',
            'provider' => 'resend',
            'from_type' => 'email',
            'from_value' => $highIntentContact->email,
            'body' => 'I am interested. Can we talk Thursday?',
            'reply_semantic_category' =>
                ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
            'inbox_status' => InboundMessage::INBOX_STATUS_REVIEWED,
            'reviewed_at' => now()->subMinutes(35),
            'received_at' => now()->subMinutes(40),
        ]);
        $doneReply = $this->reply($routineContact, [
            'body' => 'This reply is already complete.',
            'reply_semantic_category' =>
                ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
            'inbox_status' => InboundMessage::INBOX_STATUS_DONE,
            'completed_at' => now()->subMinutes(45),
            'received_at' => now()->subMinutes(50),
        ]);
        $routedIntake = $this->reply($highIntentContact, [
            'channel' => 'email',
            'provider' => 'resend',
            'from_type' => 'email',
            'from_value' => 'vendor@example.test',
            'body' => 'Vendor intake payload.',
            'reply_semantic_category' =>
                ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
            'inbound_email_route_key' => 'vendor_updates',
            'received_at' => now()->subMinutes(5),
        ]);
        $this->reply($routineContact, [
            'body' => 'Help request.',
            'classification' => InboundMessage::CLASSIFICATION_HELP,
            'inbox_status' => InboundMessage::INBOX_STATUS_NEW,
            'received_at' => now()->subMinutes(2),
        ]);

        $digest = app(DailyFollowUpReportBuilder::class)->build(
            parameters: $this->replyOnlyParameters(),
            now: now(),
            timezone: 'America/Chicago',
        );
        $section = collect($digest['sections'])->firstWhere(
            'key',
            'new_replies',
        );

        $this->assertIsArray($section);
        $this->assertSame(2, $section['count']);
        $this->assertSame(2, $digest['total_count']);
        $this->assertCount(2, $section['items']);
        $this->assertSame(
            [
                ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
                ReplySemanticAssessment::CATEGORY_ROUTINE,
            ],
            collect($section['items'])
                ->pluck('semantic_category')
                ->all(),
        );
        $this->assertSame(
            [
                route('crm.inbound-messaging.inbox.show', $highIntentReply),
                route('crm.inbound-messaging.inbox.show', $routineReply),
            ],
            collect($section['items'])->pluck('url')->all(),
        );
        $this->assertStringContainsString(
            'Jordan Realtor',
            $section['items'][0]['title'],
        );
        $this->assertStringContainsString(
            'Alex Buyer',
            $section['items'][1]['title'],
        );

        $details = implode("\n", collect($section['items'])
            ->pluck('detail')
            ->all());

        $this->assertStringContainsString($routineReply->body, $details);
        $this->assertStringContainsString($highIntentReply->body, $details);
        $this->assertStringNotContainsString($doneReply->body, $details);
        $this->assertStringNotContainsString($routedIntake->body, $details);
    }

    public function test_reply_section_orders_each_semantic_category_and_treats_unassessed_history_as_review_work(): void
    {
        Carbon::setTestNow('2026-09-28 18:30:00 UTC');

        $contact = Contact::factory()->create();
        $cases = [
            [
                'category' => ReplySemanticAssessment::CATEGORY_ROUTINE,
                'minutes_ago' => 1,
            ],
            [
                'category' => ReplySemanticAssessment::CATEGORY_NEGATIVE,
                'minutes_ago' => 2,
            ],
            [
                'category' => ReplySemanticAssessment::CATEGORY_POSITIVE_DEFERRED,
                'minutes_ago' => 3,
            ],
            [
                'category' => ReplySemanticAssessment::CATEGORY_NEEDS_REVIEW,
                'minutes_ago' => 4,
            ],
            [
                'category' => null,
                'minutes_ago' => 5,
            ],
            [
                'category' => ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
                'minutes_ago' => 30,
            ],
        ];

        foreach ($cases as $index => $case) {
            $this->reply($contact, [
                'provider_event_id' => 'semantic-event-'.$index,
                'provider_message_id' => 'semantic-message-'.$index,
                'body' => 'Semantic reply '.$index,
                'reply_semantic_category' => $case['category'],
                'received_at' => now()->subMinutes($case['minutes_ago']),
            ]);
        }

        $digest = app(DailyFollowUpReportBuilder::class)->build(
            parameters: $this->replyOnlyParameters(),
            now: now(),
            timezone: 'America/Chicago',
        );
        $section = collect($digest['sections'])->firstWhere(
            'key',
            'new_replies',
        );

        $this->assertIsArray($section);
        $this->assertSame(6, $section['count']);
        $this->assertSame(
            [
                ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
                ReplySemanticAssessment::CATEGORY_NEEDS_REVIEW,
                ReplySemanticAssessment::CATEGORY_NEEDS_REVIEW,
                ReplySemanticAssessment::CATEGORY_POSITIVE_DEFERRED,
                ReplySemanticAssessment::CATEGORY_NEGATIVE,
                ReplySemanticAssessment::CATEGORY_ROUTINE,
            ],
            collect($section['items'])
                ->pluck('semantic_category')
                ->all(),
        );
    }

    public function test_reply_only_provider_carries_reply_copy_and_direct_inbox_link_into_email_content(): void
    {
        Carbon::setTestNow('2026-09-28 18:30:00 UTC');

        $contact = Contact::factory()->create([
            'first_name' => 'Casey',
            'last_name' => 'Client',
            'name' => null,
        ]);
        $reply = $this->reply($contact, [
            'body' => 'Yes, please call me this afternoon.',
            'reply_semantic_category' =>
                ReplySemanticAssessment::CATEGORY_HIGH_INTENT,
            'received_at' => now()->subMinutes(15),
        ]);

        $result = app(DailyFollowUpScheduledReportProvider::class)->build(
            parameters: $this->replyOnlyParameters(),
            generatedAt: now(),
            timezone: 'America/Chicago',
        );
        $body = implode("\n", $result->body);

        $this->assertSame(1, $result->meta['total_count']);
        $this->assertSame(
            1,
            $result->meta['section_counts']['new_replies'],
        );
        $this->assertStringContainsString($reply->body, $body);
        $this->assertStringContainsString(
            route('crm.inbound-messaging.inbox.show', $reply),
            $body,
        );
    }

    /** @return array<string, mixed> */
    private function replyOnlyParameters(): array
    {
        return [
            'include_replies' => true,
            'include_tasks' => false,
            'include_appointments' => false,
            'new_lead_status_keys' => [],
            'incomplete_application_status_keys' => [],
            'next_action_status_keys' => [],
            'follow_up_status_days' => [],
            'section_limit' => 20,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function reply(Contact $contact, array $overrides = []): InboundMessage
    {
        return InboundMessage::query()->create(array_replace([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'related_contact_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'from_type' => 'phone',
            'from_value' => '+15551234567',
            'body' => 'Reply body.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now(),
            'inbox_status' => InboundMessage::INBOX_STATUS_NEW,
        ], $overrides));
    }
}
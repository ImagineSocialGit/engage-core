<?php

namespace Tests\Feature\Reporting;

use App\Modules\Core\Models\Contact;
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

    public function test_reply_section_keeps_unresolved_conversation_replies_until_done_and_excludes_routed_intake(): void
    {
        Carbon::setTestNow('2026-09-28 18:30:00 UTC');

        $newContact = Contact::factory()->create([
            'first_name' => 'Alex',
            'last_name' => 'Buyer',
            'name' => null,
        ]);
        $reviewedContact = Contact::factory()->create([
            'first_name' => 'Jordan',
            'last_name' => 'Realtor',
            'name' => null,
        ]);

        $newReply = $this->reply($newContact, [
            'channel' => 'sms',
            'body' => 'I am interested. Can we talk Thursday?',
            'inbox_status' => InboundMessage::INBOX_STATUS_NEW,
            'received_at' => now()->subMinutes(10),
        ]);
        $reviewedReply = $this->reply($reviewedContact, [
            'channel' => 'email',
            'provider' => 'resend',
            'from_type' => 'email',
            'from_value' => $reviewedContact->email,
            'body' => 'I saw this. I can follow up tomorrow morning.',
            'inbox_status' => InboundMessage::INBOX_STATUS_REVIEWED,
            'reviewed_at' => now()->subMinutes(25),
            'received_at' => now()->subMinutes(30),
        ]);
        $doneReply = $this->reply($newContact, [
            'body' => 'This reply is already complete.',
            'inbox_status' => InboundMessage::INBOX_STATUS_DONE,
            'completed_at' => now()->subMinutes(40),
            'received_at' => now()->subMinutes(45),
        ]);
        $routedIntake = $this->reply($reviewedContact, [
            'channel' => 'email',
            'provider' => 'resend',
            'from_type' => 'email',
            'from_value' => 'vendor@example.test',
            'body' => 'Vendor intake payload.',
            'inbound_email_route_key' => 'vendor_updates',
            'received_at' => now()->subMinutes(5),
        ]);
        $this->reply($newContact, [
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
            ['Alex Buyer', 'Jordan Realtor'],
            collect($section['items'])->pluck('title')->all(),
        );
        $this->assertSame(
            [
                route('crm.inbound-messaging.inbox.show', $newReply),
                route('crm.inbound-messaging.inbox.show', $reviewedReply),
            ],
            collect($section['items'])->pluck('url')->all(),
        );

        $details = implode("\n", collect($section['items'])
            ->pluck('detail')
            ->all());

        $this->assertStringContainsString($newReply->body, $details);
        $this->assertStringContainsString($reviewedReply->body, $details);
        $this->assertStringNotContainsString($doneReply->body, $details);
        $this->assertStringNotContainsString($routedIntake->body, $details);
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
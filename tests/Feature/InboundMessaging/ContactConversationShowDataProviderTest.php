<?php

namespace Tests\Feature\InboundMessaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\ContactShow\ContactConversationShowDataProvider;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactConversationShowDataProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_conversation_combines_inbound_context_and_manual_crm_replies(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);

        $outbound = ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->sent()
            ->create([
                'purpose' => 'marketing',
                'scope' => 'mortgage_homebuyer_nurture',
                'message_type' => 'follow_up',
                'payload' => [
                    'to' => 'person@example.test',
                    'subject' => 'Are you still thinking about buying?',
                    'body' => 'Long outbound email body does not need to be repeated here.',
                ],
                'send_at' => now()->subMinutes(10),
            ]);

        $inbound = InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'contact-conversation-reply-1',
            'provider_message_id' => 'provider-message-1',
            'message_id' => '<provider-message-1@example.test>',
            'from_type' => 'email',
            'from_value' => 'person@example.test',
            'to_type' => 'email',
            'to_value' => 'team@example.test',
            'subject' => 'Re: Are you still thinking about buying?',
            'body' => 'Yes, I am ready. Please call me tomorrow.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'purpose' => 'marketing',
            'scope' => 'mortgage_homebuyer_nurture',
            'correlated_scheduled_message_id' => $outbound->getKey(),
            'reply_intent_key' => 'high_intent',
            'reply_correlation_method' => 'exact',
            'received_at' => now()->subMinutes(5),
            'processed_at' => now()->subMinutes(4),
            'meta' => [],
        ]);

        ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->create([
                'purpose' => 'marketing',
                'scope' => 'mortgage_homebuyer_nurture',
                'message_type' => 'conversation_reply',
                'payload' => [
                    'to' => 'person@example.test',
                    'subject' => 'Re: Are you still thinking about buying?',
                    'body' => 'Absolutely. I can call you tomorrow morning.',
                ],
                'send_at' => now()->subMinute(),
                'status' => ScheduledMessage::STATUS_PENDING,
            ]);

        $data = app(ContactConversationShowDataProvider::class)->dataFor($contact);
        $items = collect($data['conversationItems']);

        $this->assertCount(3, $items);
        $this->assertSame('outbound', $items[0]['direction']);
        $this->assertSame(
            'Absolutely. I can call you tomorrow morning.',
            $items[0]['body'],
        );
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $items[0]['status']);
        $this->assertSame('inbound', $items[1]['direction']);
        $this->assertSame(
            'Re: Are you still thinking about buying?',
            $items[1]['title'],
        );
        $this->assertSame(
            'Yes, I am ready. Please call me tomorrow.',
            $items[1]['body'],
        );
        $this->assertSame('High Intent', $items[1]['intent']);
        $this->assertSame('outbound', $items[2]['direction']);
        $this->assertSame(
            'Are you still thinking about buying?',
            $items[2]['title'],
        );
        $this->assertNull($items[2]['body']);
        $this->assertSame(
            'inbound-'.$inbound->getKey(),
            $data['latestInboundReply']['id'],
        );
        $this->assertSame(
            'Re: Are you still thinking about buying?',
            data_get($data, 'conversationReply.subject'),
        );
    }

    public function test_reply_context_uses_existing_channel_purpose_permission_when_scope_is_missing(): void
    {
        config()->set('messaging.consent.channel_purpose_domains.sms.marketing', 'marketing');
        config()->set('messaging.consent_domains.marketing', [
            'topic' => 'marketing communications',
            'scopes' => [],
            'scope_prefixes' => [],
            'opt_in' => [],
        ]);

        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'marketing',
            'consented_at' => now(),
            'source' => 'test',
        ]);

        $inbound = InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'contact-conversation-reply-context',
            'from_type' => 'phone',
            'from_value' => '+15551234567',
            'to_type' => 'phone',
            'to_value' => '+15557654321',
            'body' => 'Please call me tomorrow.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'purpose' => 'marketing',
            'scope' => null,
            'received_at' => now(),
            'meta' => [],
        ]);

        $data = app(ContactConversationShowDataProvider::class)->dataFor($contact);

        $this->assertSame(
            $inbound->getKey(),
            data_get($data, 'conversationReply.inbound_message_id'),
        );
        $this->assertSame('sms', data_get($data, 'conversationReply.channel'));
        $this->assertSame('marketing', data_get($data, 'conversationReply.scope'));
        $this->assertTrue(data_get($data, 'conversationReply.can_send'));
        $this->assertNull(data_get($data, 'conversationReply.unavailable_reason'));
    }

    public function test_conversation_trims_quoted_email_history_from_existing_inbound_rows(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);

        InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'contact-conversation-quoted-reply',
            'from_type' => 'email',
            'from_value' => 'person@example.test',
            'to_type' => 'email',
            'to_value' => 'reply@example.test',
            'subject' => 'Re: This is a test!',
            'body' => "Hello!\n\n____________________________\nFrom: Team <team@example.test>\nSent: Saturday\nTo: Person <person@example.test>\nSubject: This is a test!\n\nPrior message with a signed unsubscribe URL.",
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'purpose' => 'marketing',
            'scope' => 'mortgage_homebuyer_nurture',
            'received_at' => now(),
        ]);

        $data = app(ContactConversationShowDataProvider::class)->dataFor($contact);

        $this->assertSame('Hello!', data_get($data, 'latestInboundReply.body'));
        $this->assertSame('Hello!', data_get($data, 'conversationItems.0.body'));
    }

    public function test_registered_provider_surfaces_conversation_as_contact_work_rail(): void
    {
        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
        ]);

        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'contact-conversation-page-1',
            'from_type' => 'phone',
            'from_value' => '+15551234567',
            'to_type' => 'phone',
            'to_value' => '+15557654321',
            'body' => 'Please call me tomorrow.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'purpose' => 'marketing',
            'scope' => 'general',
            'received_at' => now(),
            'meta' => [],
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get(route('crm.contacts.show', $contact))
            ->assertOk()
            ->assertSee('data-contact-conversation-rail', false)
            ->assertSee('Please call me tomorrow.')
            ->assertDontSee('What’s already happening');
    }

    public function test_non_reply_inbound_commands_do_not_pollute_contact_conversation(): void
    {
        $contact = Contact::factory()->create();

        InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'contact-conversation-stop-1',
            'from_type' => 'phone',
            'from_value' => '+15551234567',
            'to_type' => 'phone',
            'to_value' => '+15557654321',
            'body' => 'STOP',
            'classification' => InboundMessage::CLASSIFICATION_CONSENT_REVOCATION,
            'purpose' => 'marketing',
            'scope' => 'general',
            'received_at' => now(),
            'meta' => [],
        ]);

        $data = app(ContactConversationShowDataProvider::class)->dataFor($contact);

        $this->assertEquals([], $data['conversationItems']);
        $this->assertNull($data['latestInboundReply']);
        $this->assertNull($data['conversationReply']);
    }
}
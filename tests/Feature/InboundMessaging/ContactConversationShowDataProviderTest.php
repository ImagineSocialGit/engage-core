<?php

namespace Tests\Feature\InboundMessaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\ContactShow\ContactConversationShowDataProvider;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactConversationShowDataProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_conversation_combines_normal_replies_with_recent_sent_context(): void
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

        InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'contact-conversation-reply-1',
            'provider_message_id' => 'provider-message-1',
            'from_type' => 'email',
            'from_value' => 'person@example.test',
            'to_type' => 'email',
            'to_value' => 'team@example.test',
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

        $data = app(ContactConversationShowDataProvider::class)->dataFor($contact);
        $items = collect($data['conversationItems']);

        $this->assertCount(2, $items);
        $this->assertSame('inbound', $items[0]['direction']);
        $this->assertSame(
            'Yes, I am ready. Please call me tomorrow.',
            $items[0]['body'],
        );
        $this->assertSame('High Intent', $items[0]['intent']);
        $this->assertSame('outbound', $items[1]['direction']);
        $this->assertSame(
            'Are you still thinking about buying?',
            $items[1]['title'],
        );
        $this->assertNull($items[1]['body']);
        $this->assertSame(
            $items[0]['id'],
            $data['latestInboundReply']['id'],
        );
    }


    public function test_registered_provider_surfaces_contact_conversation_on_contact_workspace(): void
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
            ->assertSee('data-module-panel="inbound_messaging"', false)
            ->assertSee('Please call me tomorrow.');
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
    }
}
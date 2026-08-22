<?php

namespace Tests\Feature\InboundMessaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ContactConversationReplyControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
        ]);

        Queue::fake();
        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_operator_can_queue_same_channel_email_reply_from_contact_conversation(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);
        $this->grant($contact, 'email', 'marketing', 'general');

        $source = ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->sent()
            ->create([
                'purpose' => 'marketing',
                'scope' => 'general',
                'message_type' => 'check_in',
                'reply_profile_key' => 'test_reply_profile',
                'payload' => [
                    'to' => 'person@example.test',
                    'subject' => 'Checking in',
                    'body' => 'How are things going?',
                ],
            ]);
        $inbound = $this->inboundReply(
            contact: $contact,
            source: $source,
            channel: 'email',
        );

        $this->actingAs($user)
            ->post(route('crm.contacts.conversation.reply.store', [
                $contact,
                $inbound,
            ]), [
                'reply_body' => 'Yes — I can call you tomorrow morning.',
                'reply_subject' => '',
                'reply_request_key' => 'reply-request-1',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact).'#contact-conversation')
            ->assertSessionHas('success', 'Reply queued for delivery.');

        $reply = ScheduledMessage::query()
            ->where('message_type', 'conversation_reply')
            ->sole();

        $this->assertSame($contact->getMorphClass(), $reply->recipient_type);
        $this->assertSame($contact->getKey(), $reply->recipient_id);
        $this->assertSame('email', $reply->channel);
        $this->assertSame('marketing', $reply->purpose);
        $this->assertSame('general', $reply->scope);
        $this->assertSame('emails', $reply->queue);
        $this->assertSame('test_reply_profile', $reply->reply_profile_key);
        $this->assertSame('Re: Checking in', data_get($reply->payload, 'subject'));
        $this->assertSame(
            'Yes — I can call you tomorrow morning.',
            data_get($reply->payload, 'body'),
        );
        $this->assertSame('crm_contact_conversation', data_get($reply->meta, 'surface'));

        Queue::assertPushed(
            SendScheduledMessageJob::class,
            fn (SendScheduledMessageJob $job): bool =>
                $job->scheduledMessageId === $reply->getKey(),
        );
    }

    public function test_operator_can_queue_sms_reply_without_bypassing_messaging_gate(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);
        $this->grant($contact, 'sms', 'marketing', 'general');
        $inbound = $this->inboundReply(
            contact: $contact,
            source: null,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'general',
        );

        $this->actingAs($user)
            ->post(route('crm.contacts.conversation.reply.store', [
                $contact,
                $inbound,
            ]), [
                'reply_body' => 'I can call you tomorrow at 10.',
                'reply_request_key' => 'reply-request-sms-1',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact).'#contact-conversation');

        $reply = ScheduledMessage::query()
            ->where('message_type', 'conversation_reply')
            ->sole();

        $this->assertSame('sms', $reply->channel);
        $this->assertSame('sms', $reply->queue);
        $this->assertSame(
            'I can call you tomorrow at 10.',
            data_get($reply->payload, 'message'),
        );
    }

    public function test_same_reply_request_key_is_idempotent(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);
        $this->grant($contact, 'sms', 'marketing', 'general');
        $inbound = $this->inboundReply(
            contact: $contact,
            source: null,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'general',
        );
        $payload = [
            'reply_body' => 'Thanks — I will call shortly.',
            'reply_request_key' => 'same-request-key',
        ];

        $this->actingAs($user)
            ->post(route('crm.contacts.conversation.reply.store', [$contact, $inbound]), $payload)
            ->assertRedirect();
        $this->actingAs($user)
            ->post(route('crm.contacts.conversation.reply.store', [$contact, $inbound]), $payload)
            ->assertRedirect();

        $this->assertSame(
            1,
            ScheduledMessage::query()
                ->where('message_type', 'conversation_reply')
                ->count(),
        );
        Queue::assertPushedTimes(SendScheduledMessageJob::class, 1);
    }

    public function test_reply_is_rejected_when_messaging_permission_is_not_active(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);
        $inbound = $this->inboundReply(
            contact: $contact,
            source: null,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'general',
        );

        $this->actingAs($user)
            ->from(route('crm.contacts.show', $contact))
            ->post(route('crm.contacts.conversation.reply.store', [$contact, $inbound]), [
                'reply_body' => 'This should not be queued.',
                'reply_request_key' => 'blocked-request-key',
            ])
            ->assertSessionHasErrors('reply_body');

        $this->assertDatabaseMissing('scheduled_messages', [
            'recipient_id' => $contact->getKey(),
            'message_type' => 'conversation_reply',
        ]);
        Queue::assertNothingPushed();
    }

    public function test_reply_route_does_not_accept_another_contacts_inbound_message(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'phone' => '+15551234567',
        ]);
        $other = Contact::factory()->create([
            'phone' => '+15557654321',
        ]);
        $inbound = $this->inboundReply(
            contact: $other,
            source: null,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'general',
        );

        $this->actingAs($user)
            ->post(route('crm.contacts.conversation.reply.store', [$contact, $inbound]), [
                'reply_body' => 'Wrong contact.',
                'reply_request_key' => 'wrong-contact-key',
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('scheduled_messages', [
            'recipient_id' => $contact->getKey(),
            'message_type' => 'conversation_reply',
        ]);
    }

    private function grant(
        Contact $contact,
        string $channel,
        string $purpose,
        string $scope,
    ): void {
        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => $channel,
            'purpose' => $purpose,
            'scope' => $scope,
            'consented_at' => now(),
            'source' => 'test',
        ]);
    }

    private function inboundReply(
        Contact $contact,
        ?ScheduledMessage $source,
        string $channel,
        ?string $purpose = null,
        ?string $scope = null,
    ): InboundMessage {
        return InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'client_key' => 'test-client',
            'channel' => $channel,
            'provider' => $channel === 'email' ? 'resend' : 'telnyx',
            'provider_event_id' => 'reply-event-'.uniqid(),
            'provider_message_id' => 'reply-message-'.uniqid(),
            'from_type' => $channel === 'email' ? 'email' : 'phone',
            'from_value' => $channel === 'email' ? $contact->email : $contact->phone,
            'to_type' => $channel === 'email' ? 'email' : 'phone',
            'to_value' => $channel === 'email' ? 'reply@example.test' : '+15550000000',
            'body' => 'Please contact me.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'purpose' => $purpose ?? $source?->purpose,
            'scope' => $scope ?? $source?->scope,
            'correlated_scheduled_message_id' => $source?->getKey(),
            'reply_intent_key' => null,
            'reply_correlation_method' => $source ? 'exact' : 'none',
            'received_at' => now(),
            'meta' => [],
        ]);
    }
}
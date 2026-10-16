<?php

namespace Tests\Feature\InboundMessaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundInboxWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);
    }


    public function test_recording_sets_only_human_review_messages_as_open_inbox_work(): void
    {
        $normal = app(RecordInboundMessageAction::class)->handle([
            'client_key' => config('client.key'),
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'event-inbox-normal',
            'provider_message_id' => 'message-inbox-normal',
            'from_type' => 'email',
            'from_value' => 'person@example.test',
            'to_type' => 'email',
            'to_value' => 'reply@example.test',
            'subject' => 'Please review',
            'body' => 'I need help with this.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now(),
        ]);

        $handled = app(RecordInboundMessageAction::class)->handle([
            'client_key' => config('client.key'),
            'channel' => 'sms',
            'provider' => 'telnyx',
            'provider_event_id' => 'event-inbox-help',
            'provider_message_id' => 'message-inbox-help',
            'from_type' => 'phone',
            'from_value' => '+15555550123',
            'to_type' => 'phone',
            'to_value' => '+15555550999',
            'body' => 'HELP',
            'classification' => InboundMessage::CLASSIFICATION_HELP,
            'received_at' => now(),
        ]);

        $this->assertSame(
            InboundMessage::INBOX_STATUS_NEW,
            $normal->inbox_status,
        );
        $this->assertNull($normal->reviewed_at);
        $this->assertNull($normal->completed_at);

        $this->assertSame(
            InboundMessage::INBOX_STATUS_DONE,
            $handled->inbox_status,
        );
        $this->assertNull($handled->reviewed_at);
        $this->assertNotNull($handled->completed_at);
    }


    public function test_operator_can_move_message_through_human_triage_states(): void
    {
        $user = User::factory()->create();
        $message = $this->message();

        $this->actingAs($user)
            ->patch(
                route('crm.inbound-messaging.inbox.state', $message),
                ['inbox_status' => InboundMessage::INBOX_STATUS_REVIEWED],
            )
            ->assertRedirect(
                route('crm.inbound-messaging.inbox.show', $message),
            );

        $this->assertSame(
            InboundMessage::INBOX_STATUS_REVIEWED,
            $message->refresh()->inbox_status,
        );
        $this->assertNotNull($message->reviewed_at);
        $this->assertNull($message->completed_at);

        $this->actingAs($user)
            ->patch(
                route('crm.inbound-messaging.inbox.state', $message),
                ['inbox_status' => InboundMessage::INBOX_STATUS_DONE],
            )
            ->assertRedirect();

        $this->assertSame(
            InboundMessage::INBOX_STATUS_DONE,
            $message->refresh()->inbox_status,
        );
        $this->assertNotNull($message->completed_at);

        $this->actingAs($user)
            ->patch(
                route('crm.inbound-messaging.inbox.state', $message),
                ['inbox_status' => InboundMessage::INBOX_STATUS_NEW],
            )
            ->assertRedirect();

        $message->refresh();

        $this->assertSame(
            InboundMessage::INBOX_STATUS_NEW,
            $message->inbox_status,
        );
        $this->assertNull($message->reviewed_at);
        $this->assertNull($message->completed_at);
    }

    public function test_operator_can_link_contactless_message_to_existing_person_without_changing_sender(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'Jane Person',
            'email' => 'jane@example.test',
        ]);
        $message = $this->message([
            'from_value' => 'notifications@vendor.example',
        ]);

        $this->actingAs($user)
            ->patch(
                route('crm.inbound-messaging.inbox.person.link', $message),
                ['contact_id' => $contact->getKey()],
            )
            ->assertRedirect();

        $message->refresh();

        $this->assertSame(
            $contact->getKey(),
            $message->related_contact_id,
        );
        $this->assertNull($message->sender_id);
        $this->assertSame(
            'notifications@vendor.example',
            $message->from_value,
        );

        $this->actingAs($user)
            ->get(route('crm.inbound-messaging.inbox.show', $message))
            ->assertOk();
    }

    public function test_operator_can_create_and_link_person_from_message(): void
    {
        $user = User::factory()->create();
        $message = $this->message([
            'from_value' => 'person@example.test',
        ]);

        $this->actingAs($user)
            ->post(
                route('crm.inbound-messaging.inbox.person.create', $message),
                [
                    'name' => 'New Person',
                    'email' => 'new.person@example.test',
                    'phone' => '+15555550123',
                ],
            )
            ->assertRedirect();

        $contact = Contact::query()
            ->where('email', 'new.person@example.test')
            ->sole();

        $this->assertSame('New Person', $contact->name);
        $this->assertSame('inbound_messaging', $contact->source);
        $this->assertSame(
            $contact->getKey(),
            $message->refresh()->related_contact_id,
        );
    }


    public function test_automatically_answered_message_is_done_and_not_dashboard_review_work(): void
    {
        $contact = Contact::factory()->create();
        $response = ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->sent()
            ->create([
                'message_type' => 'reply_acknowledgement',
            ]);
        $message = $this->message([
            'subject' => 'Automatically answered message',
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'related_contact_id' => $contact->getKey(),
            'inbox_status' => InboundMessage::INBOX_STATUS_DONE,
            'completed_at' => now(),
            'automated_response_scheduled_message_id' => $response->getKey(),
            'automated_handled_at' => now(),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.inbound-messaging.inbox.index', [
                'status' => 'done',
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('crm.index'))
            ->assertOk();

        $this->assertSame(
            InboundMessage::INBOX_STATUS_DONE,
            $message->fresh()->inbox_status,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function message(array $overrides = []): InboundMessage
    {
        return InboundMessage::query()->create(array_replace([
            'client_key' => config('client.key'),
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'event-'.uniqid(),
            'provider_message_id' => 'message-'.uniqid(),
            'from_type' => 'email',
            'from_value' => 'sender@example.test',
            'to_type' => 'email',
            'to_value' => 'reply@example.test',
            'subject' => 'Inbound message',
            'body' => 'Please review this message.',
            'classification' => InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now(),
            'inbox_status' => InboundMessage::INBOX_STATUS_NEW,
        ], $overrides));
    }
}
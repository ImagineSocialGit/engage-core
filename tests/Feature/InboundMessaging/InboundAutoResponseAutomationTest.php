<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Automation\MarkInboundMessageAutoRespondedActionHandler;
use App\Modules\InboundMessaging\Listeners\RecordInboundAutomaticMessage;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\Messaging\Automation\SendMessageAutomationActionHandler;
use App\Modules\Messaging\Data\Automation\SendMessageAutomationDefinition;
use App\Modules\Messaging\Events\AutomationMessageScheduled;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class InboundAutoResponseAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_message_sends_and_records_the_response_as_one_action(): void
    {
        $contact = Contact::factory()->create();
        $inbound = $this->inboundMessage($contact);
        $response = ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->create([
                'message_type' => 'reply_acknowledgement',
                'status' => ScheduledMessage::STATUS_PENDING,
            ]);
        $sendResult = AutomationActionResult::completed(
            reason: 'message_scheduled',
            artifacts: [$response],
            correlationKey: 'scheduled_message.id',
            correlationType: 'scheduled_message',
            correlation: [
                'scheduled_message_ids' => [$response->getKey()],
            ],
            output: [
                'scheduled_messages' => [[
                    'id' => $response->getKey(),
                    'channel' => 'email',
                ]],
            ],
        );
        $sendMessage = Mockery::mock(
            SendMessageAutomationActionHandler::class,
        );
        $sendMessage->shouldReceive('handle')
            ->once()
            ->andReturn($sendResult);

        $result = (new MarkInboundMessageAutoRespondedActionHandler(
            $sendMessage,
        ))->handle($this->context($contact, $inbound));

        $this->assertSame(
            AutomationActionResult::STATUS_COMPLETED,
            $result->status,
        );
        $this->assertSame(
            'inbound_automatic_message_scheduled',
            $result->reason,
        );
        $this->assertSame([$response], $result->artifacts);
        $this->assertSame(
            'scheduled_message.id',
            $result->correlationKey,
        );

        $inbound->refresh();

        $this->assertSame(
            InboundMessage::INBOX_STATUS_DONE,
            $inbound->inbox_status,
        );
        $this->assertSame(
            $response->getKey(),
            $inbound->automated_response_scheduled_message_id,
        );
        $this->assertNotNull($inbound->automated_handled_at);
        $this->assertNotNull($inbound->completed_at);
    }

    public function test_unscheduled_automatic_message_leaves_inbound_message_open(): void
    {
        $contact = Contact::factory()->create();
        $inbound = $this->inboundMessage($contact);
        $sendMessage = Mockery::mock(
            SendMessageAutomationActionHandler::class,
        );
        $sendMessage->shouldReceive('handle')
            ->once()
            ->andReturn(AutomationActionResult::skipped(
                'send_message_no_messages_scheduled',
            ));

        $result = (new MarkInboundMessageAutoRespondedActionHandler(
            $sendMessage,
        ))->handle($this->context($contact, $inbound));

        $this->assertSame(
            AutomationActionResult::STATUS_SKIPPED,
            $result->status,
        );

        $inbound->refresh();

        $this->assertSame(
            InboundMessage::INBOX_STATUS_NEW,
            $inbound->inbox_status,
        );
        $this->assertNull(
            $inbound->automated_response_scheduled_message_id,
        );
        $this->assertNull($inbound->automated_handled_at);
        $this->assertNull($inbound->completed_at);
    }

    public function test_automatic_message_requires_exactly_one_scheduled_response(): void
    {
        $contact = Contact::factory()->create();
        $inbound = $this->inboundMessage($contact);
        $sendMessage = Mockery::mock(
            SendMessageAutomationActionHandler::class,
        );
        $sendMessage->shouldReceive('handle')
            ->once()
            ->andReturn(AutomationActionResult::completed(
                reason: 'message_scheduled',
                artifacts: [],
            ));

        $result = (new MarkInboundMessageAutoRespondedActionHandler(
            $sendMessage,
        ))->handle($this->context($contact, $inbound));

        $this->assertSame(
            AutomationActionResult::STATUS_FAILED,
            $result->status,
        );
        $this->assertSame(
            'inbound_automatic_message_requires_one_scheduled_message',
            $result->reason,
        );

        $inbound->refresh();

        $this->assertSame(
            InboundMessage::INBOX_STATUS_NEW,
            $inbound->inbox_status,
        );
        $this->assertNull(
            $inbound->automated_response_scheduled_message_id,
        );
    }

    public function test_reply_message_event_records_the_automatic_response_on_the_inbound_message(): void
    {
        $contact = Contact::factory()->create();
        $inbound = $this->inboundMessage($contact);
        $response = ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->create();
        $context = $this->context($contact, $inbound);
        $definition = SendMessageAutomationDefinition::from(array_replace(
            $context->input,
            ['message_role' => SendMessageAutomationDefinition::ROLE_REPLY],
        ));

        (new RecordInboundAutomaticMessage())->handle(
            new AutomationMessageScheduled(
                context: $context,
                definition: $definition,
                scheduledMessages: [$response],
            ),
        );

        $inbound->refresh();

        $this->assertSame(
            InboundMessage::INBOX_STATUS_DONE,
            $inbound->inbox_status,
        );
        $this->assertSame(
            $response->getKey(),
            $inbound->automated_response_scheduled_message_id,
        );
    }

    public function test_initiatory_message_event_does_not_complete_inbound_work(): void
    {
        $contact = Contact::factory()->create();
        $inbound = $this->inboundMessage($contact);
        $response = ScheduledMessage::factory()
            ->forContact($contact)
            ->email()
            ->create();

        (new RecordInboundAutomaticMessage())->handle(
            new AutomationMessageScheduled(
                context: $this->context($contact, $inbound),
                definition: SendMessageAutomationDefinition::from([
                    'message_role' => SendMessageAutomationDefinition::ROLE_INITIATORY,
                    'message_template_key' => 'email.test.initiatory',
                ]),
                scheduledMessages: [$response],
            ),
        );

        $inbound->refresh();

        $this->assertSame(
            InboundMessage::INBOX_STATUS_NEW,
            $inbound->inbox_status,
        );
        $this->assertNull(
            $inbound->automated_response_scheduled_message_id,
        );
    }

    private function context(
        Contact $contact,
        InboundMessage $inbound,
    ): AutomationActionContext {
        return new AutomationActionContext(
            input: [
                'message_template_keys_by_channel' => [
                    'email' => 'email.test.automatic_message',
                    'sms' => 'sms.test.automatic_message',
                ],
                'message_template_channel_context_path' =>
                    'automation_event.payload.inbound_message.channel',
                'on_no_messages' => 'skipped',
            ],
            subject: $inbound,
            models: [
                'current_contact' => $contact,
                'current_subject' => $inbound,
            ],
            source: $contact,
            executionKey: 'route:test:automatic-message',
            surface: 'flow_routes',
            executionContext: [
                'automation_event' => [
                    'payload' => [
                        'inbound_message' => ['channel' => 'email'],
                    ],
                ],
            ],
        );
    }

    private function inboundMessage(Contact $contact): InboundMessage
    {
        return InboundMessage::query()->create([
            'sender_type' => $contact->getMorphClass(),
            'sender_id' => $contact->getKey(),
            'related_contact_id' => $contact->getKey(),
            'client_key' => config('client.key'),
            'channel' => 'email',
            'provider' => 'resend',
            'provider_event_id' => 'auto-response-'.uniqid(),
            'provider_message_id' => 'provider-'.uniqid(),
            'from_type' => 'email',
            'from_value' => 'person@example.test',
            'to_type' => 'email',
            'to_value' => 'reply@example.test',
            'subject' => 'High-intent reply',
            'body' => 'Yes, please call me.',
            'classification' =>
                InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            'received_at' => now(),
            'inbox_status' => InboundMessage::INBOX_STATUS_NEW,
        ]);
    }
}
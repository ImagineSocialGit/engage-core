<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Automation\SendMessageAutomationActionHandler;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DirectMessageTemplateAutomationRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_route_template_identity_pins_current_immutable_version_and_future_runs_use_new_version(): void
    {
        Queue::fake();
        config()->set('modules.enabled', ['workflow', 'flow_routes', 'messaging']);
        config()->set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => ['route_send_message_points' => true],
            'purpose_scopes' => ['transactional:general' => true],
        ]);

        $contact = Contact::factory()->create(['first_name' => 'Taylor']);
        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'general',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $preset = app(CreateReusableMessageTemplateAction::class)->handle(
            name: 'Direct Route Service Message',
            channel: 'email',
            payload: [
                'subject' => 'First subject',
                'body' => 'Hi {first_name}',
            ],
            context: new ReusableMessageTemplateAuthoringContext(
                contextKey: 'flow_routes',
                purpose: 'transactional',
                scope: 'general',
                dispatchKey: 'flow_route_send_message',
                messageType: 'flow_route_message',
                payloadClass: EmailPayload::class,
                queue: 'notifications',
                moduleKey: 'flow_routes',
                moduleLabel: 'Flow Routes',
                surface: 'route_send_message_points',
                groupKey: 'flow_routes:direct:transactional:email',
                groupLabel: 'Flow Route Messages',
                usageType: 'flow_route_direct',
                selectionContexts: ['flow_routes'],
            ),
        );

        $template = $preset->canonicalTemplate;
        $firstVersionId = $template->current_version_id;
        $handler = app(SendMessageAutomationActionHandler::class);

        $first = $handler->handle(new AutomationActionContext(
            input: [
                'message_template_key' => $preset->key,
                'on_no_messages' => 'failed',
            ],
            models: ['current_contact' => $contact],
            source: $contact,
            executionKey: 'route:test:first',
            surface: 'flow_routes',
        ));

        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $first->status);
        $firstMessage = ScheduledMessage::query()->firstOrFail();
        $this->assertSame($firstVersionId, $firstMessage->message_template_version_id);
        $this->assertSame('flow_route_message', $firstMessage->message_type);

        app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: [
                'subject' => 'Second subject',
                'body' => 'Hello {first_name}',
            ],
        );
        $secondVersionId = $template->refresh()->current_version_id;
        $this->assertNotSame($firstVersionId, $secondVersionId);

        $second = $handler->handle(new AutomationActionContext(
            input: [
                'message_template_key' => $preset->key,
                'on_no_messages' => 'failed',
            ],
            models: ['current_contact' => $contact],
            source: $contact,
            executionKey: 'route:test:second',
            surface: 'flow_routes',
        ));

        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $second->status);
        $messages = ScheduledMessage::query()->orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertSame($firstVersionId, $messages[0]->message_template_version_id);
        $this->assertSame($secondVersionId, $messages[1]->message_template_version_id);
        $this->assertSame('flow_route_message', $messages[1]->message_type);
    }

    public function test_contextual_template_selection_uses_the_inbound_channel(): void
    {
        Queue::fake();
        config()->set('modules.enabled', ['workflow', 'flow_routes', 'messaging']);
        config()->set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => ['route_send_message_points' => true],
            'purpose_scopes' => ['transactional:general' => true],
        ]);
        config()->set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => ['route_send_message_points' => true],
            'purpose_scopes' => ['transactional:general' => true],
        ]);

        $contact = Contact::factory()->create([
            'email' => 'taylor@example.test',
            'phone' => '+15555550123',
        ]);

        foreach (['email', 'sms'] as $channel) {
            MessageConsent::query()->create([
                'contact_id' => $contact->getKey(),
                'channel' => $channel,
                'purpose' => 'transactional',
                'scope' => 'general',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        $emailPreset = app(CreateReusableMessageTemplateAction::class)->handle(
            name: 'Contextual Email Acknowledgement',
            channel: 'email',
            payload: [
                'subject' => 'Email acknowledgement',
                'body' => 'Thanks for your email.',
            ],
            context: new ReusableMessageTemplateAuthoringContext(
                contextKey: 'flow_routes',
                purpose: 'transactional',
                scope: 'general',
                dispatchKey: 'flow_route_send_message',
                messageType: 'reply_acknowledgement',
                payloadClass: EmailPayload::class,
                queue: 'emails',
                moduleKey: 'flow_routes',
                moduleLabel: 'Flow Routes',
                surface: 'route_send_message_points',
                groupKey: 'flow_routes:direct:transactional:email',
                groupLabel: 'Flow Route Messages',
                usageType: 'flow_route_direct',
                selectionContexts: ['flow_routes'],
            ),
        );
        $smsPreset = app(CreateReusableMessageTemplateAction::class)->handle(
            name: 'Contextual SMS Acknowledgement',
            channel: 'sms',
            payload: ['message' => 'Thanks for your text.'],
            context: new ReusableMessageTemplateAuthoringContext(
                contextKey: 'flow_routes',
                purpose: 'transactional',
                scope: 'general',
                dispatchKey: 'flow_route_send_message',
                messageType: 'reply_acknowledgement',
                payloadClass: SmsPayload::class,
                queue: 'sms',
                moduleKey: 'flow_routes',
                moduleLabel: 'Flow Routes',
                surface: 'route_send_message_points',
                groupKey: 'flow_routes:direct:transactional:sms',
                groupLabel: 'Flow Route Messages',
                usageType: 'flow_route_direct',
                selectionContexts: ['flow_routes'],
            ),
        );

        $result = app(SendMessageAutomationActionHandler::class)->handle(
            new AutomationActionContext(
                input: [
                    'message_role' => 'reply',
                    'message_template_keys_by_channel' => [
                        'email' => $emailPreset->key,
                        'sms' => $smsPreset->key,
                    ],
                    'message_template_channel_context_path' =>
                        'automation_event.payload.inbound_message.channel',
                    'on_no_messages' => 'failed',
                ],
                models: ['current_contact' => $contact],
                source: $contact,
                executionKey: 'route:test:contextual-sms',
                surface: 'flow_routes',
                executionContext: [
                    'automation_event' => [
                        'payload' => [
                            'inbound_message' => ['channel' => 'sms'],
                        ],
                    ],
                ],
            ),
        );

        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $result->status);
        $scheduled = ScheduledMessage::query()->sole();
        $this->assertSame('sms', $scheduled->channel);
        $this->assertSame(
            $smsPreset->canonicalTemplate->current_version_id,
            $scheduled->message_template_version_id,
        );
        $this->assertSame('reply_acknowledgement', $scheduled->message_type);
    }
}
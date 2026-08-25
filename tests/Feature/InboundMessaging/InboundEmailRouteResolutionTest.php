<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\InboundMessaging\Actions\Email\RecordInboundEmailAction;
use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundEmailRouteResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('messaging.email.inbound_domain', 'replies.example.test');
    }

    public function test_external_alias_resolves_to_durable_route_context_without_contact(): void
    {
        InboundEmailRoute::query()->create([
            'key' => 'arive_application',
            'local_part' => 'arive+application',
            'label' => 'Arive application',
            'source' => 'arive',
            'context_key' => 'application',
            'is_active' => true,
        ]);

        $inbound = app(RecordInboundEmailAction::class)->handle(
            provider: 'resend',
            providerEventId: 'evt_arive_application',
            providerMessageId: 'msg_arive_application',
            from: 'Arive <processing@arive.example>',
            toAddresses: ['ARIVE+APPLICATION@REPLIES.EXAMPLE.TEST'],
            text: 'Application event payload.',
            html: null,
            subject: 'Application update',
            messageId: '<arive-application@example.test>',
            receivedAt: now(),
        );

        $this->assertSame(InboundMessage::CLASSIFICATION_NORMAL_REPLY, $inbound->classification);
        $this->assertNull($inbound->sender_id);
        $this->assertNull($inbound->correlated_scheduled_message_id);
        $this->assertSame('none', $inbound->reply_correlation_method);
        $this->assertSame('arive+application@replies.example.test', $inbound->to_value);
        $this->assertSame('arive_application', $inbound->inbound_email_route_key);
        $this->assertSame('arive', $inbound->inbound_email_route_source);
        $this->assertSame('application', $inbound->inbound_email_route_context);

        $routeEvent = AutomationEventOutboxEvent::query()
            ->where('event_key', RecordInboundMessageAction::ROUTED_EMAIL_AUTOMATION_EVENT_KEY)
            ->sole();

        $this->assertNull($routeEvent->contact_id);
        $this->assertSame(
            'arive_application',
            data_get($routeEvent->payload, 'inbound_message.inbound_email_route_key'),
        );
        $this->assertSame(
            'arive',
            data_get($routeEvent->payload, 'inbound_message.inbound_email_route_source'),
        );
        $this->assertSame(
            'application',
            data_get($routeEvent->payload, 'inbound_message.inbound_email_route_context'),
        );
        $this->assertNull(data_get($routeEvent->payload, 'inbound_message.body'));

        $this->assertFalse(AutomationEventOutboxEvent::query()
            ->where('event_key', RecordInboundMessageAction::NORMAL_REPLY_AUTOMATION_EVENT_KEY)
            ->exists());
    }

    public function test_inactive_alias_does_not_resolve(): void
    {
        InboundEmailRoute::query()->create([
            'key' => 'arive_conditions',
            'local_part' => 'arive+conditions',
            'label' => 'Arive conditions',
            'source' => 'arive',
            'context_key' => 'conditions',
            'is_active' => false,
        ]);

        $inbound = app(RecordInboundEmailAction::class)->handle(
            provider: 'resend',
            providerEventId: 'evt_arive_conditions',
            providerMessageId: 'msg_arive_conditions',
            from: 'processing@arive.example',
            toAddresses: ['arive+conditions@replies.example.test'],
            text: 'Conditions event payload.',
            html: null,
            receivedAt: now(),
        );

        $this->assertNull($inbound->inbound_email_route_key);
        $this->assertNull($inbound->inbound_email_route_source);
        $this->assertNull($inbound->inbound_email_route_context);
        $this->assertDatabaseCount('automation_event_outbox_events', 0);
    }
}
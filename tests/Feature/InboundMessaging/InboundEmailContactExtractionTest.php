<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\Email\RecordInboundEmailAction;
use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundEmailContactExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set(
            'messaging.email.inbound_domain',
            'inbound.example.test',
        );
    }

    public function test_routed_email_can_create_and_link_contact_before_contact_aware_automation_event(): void
    {
        $route = $this->route([
            'fields' => [
                'email' => [
                    'source' => 'body_after_label',
                    'label' => 'Email',
                ],
                'first_name' => [
                    'source' => 'body_after_label',
                    'label' => 'First Name',
                ],
                'last_name' => [
                    'source' => 'body_after_label',
                    'label' => 'Last Name',
                ],
                'phone' => [
                    'source' => 'body_after_label',
                    'label' => 'Phone',
                ],
            ],
            'required_fields' => [
                'email',
                'first_name',
                'last_name',
            ],
        ]);

        $message = app(RecordInboundEmailAction::class)->handle(
            provider: 'resend',
            providerEventId: 'evt-lead-1',
            providerMessageId: 'msg-lead-1',
            from: 'Lead Vendor <notifications@vendor.example>',
            toAddresses: ['website-leads@inbound.example.test'],
            text: implode("\n", [
                'New website lead',
                'First Name: Jane',
                'Last Name: Doe',
                'Email: Jane.Doe@example.com',
                'Phone: 555-555-1212',
            ]),
            html: null,
            subject: 'New website lead',
            receivedAt: now(),
        );

        $contact = Contact::query()
            ->where('email', 'jane.doe@example.com')
            ->sole();

        $this->assertNull($message->sender_id);
        $this->assertSame($contact->getKey(), $message->related_contact_id);
        $this->assertSame(
            InboundMessage::CONTACT_EXTRACTION_SUCCEEDED,
            $message->contact_extraction_status,
        );
        $this->assertNull($message->contact_extraction_error);
        $this->assertNotNull($message->contact_extraction_attempted_at);
        $this->assertSame(
            64,
            strlen((string) $message->contact_extraction_definition_hash),
        );

        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);
        $this->assertSame('Jane Doe', $contact->name);
        $this->assertSame('555-555-1212', $contact->phone);
        $this->assertSame('inbound_messaging', $contact->source);
        $this->assertSame($route->key, $contact->subsource);

        $routeEvent = AutomationEventOutboxEvent::query()
            ->where(
                'event_key',
                RecordInboundMessageAction::ROUTED_EMAIL_AUTOMATION_EVENT_KEY,
            )
            ->sole();

        $this->assertSame($contact->getKey(), $routeEvent->contact_id);
        $this->assertSame(
            $route->key,
            data_get(
                $routeEvent->payload,
                'inbound_message.inbound_email_route_key',
            ),
        );
        $this->assertNull(
            data_get($routeEvent->payload, 'inbound_message.body'),
        );

        app(RecordInboundEmailAction::class)->handle(
            provider: 'resend',
            providerEventId: 'evt-lead-1',
            providerMessageId: 'msg-lead-1',
            from: 'Lead Vendor <notifications@vendor.example>',
            toAddresses: ['website-leads@inbound.example.test'],
            text: 'Email: different@example.com',
            html: null,
        );

        $this->assertDatabaseCount('contacts', 1);
        $this->assertDatabaseCount('inbound_messages', 1);
        $this->assertSame(
            1,
            AutomationEventOutboxEvent::query()
                ->where(
                    'event_key',
                    RecordInboundMessageAction::ROUTED_EMAIL_AUTOMATION_EVENT_KEY,
                )
                ->count(),
        );
    }

    public function test_extraction_enriches_existing_contact_without_replacing_existing_source(): void
    {
        $existing = Contact::factory()->create([
            'email' => 'existing@example.com',
            'first_name' => null,
            'source' => 'original_source',
            'subsource' => 'original_subsource',
        ]);

        $this->route([
            'fields' => [
                'email' => [
                    'source' => 'body_after_label',
                    'label' => 'Email',
                ],
                'first_name' => [
                    'source' => 'body_after_label',
                    'label' => 'First Name',
                ],
            ],
            'required_fields' => ['email'],
        ]);

        $message = app(RecordInboundEmailAction::class)->handle(
            provider: 'resend',
            providerEventId: 'evt-existing-contact',
            providerMessageId: 'msg-existing-contact',
            from: 'Vendor <notifications@vendor.example>',
            toAddresses: ['website-leads@inbound.example.test'],
            text: "Email: existing@example.com\nFirst Name: Jane",
            html: null,
        );

        $existing->refresh();

        $this->assertDatabaseCount('contacts', 1);
        $this->assertSame('Jane', $existing->first_name);
        $this->assertSame('original_source', $existing->source);
        $this->assertSame('original_subsource', $existing->subsource);
        $this->assertSame($existing->getKey(), $message->related_contact_id);
    }

    public function test_required_extraction_failure_creates_no_partial_contact_and_leaves_contactless_route_event(): void
    {
        $route = $this->route([
            'fields' => [
                'email' => [
                    'source' => 'body_after_label',
                    'label' => 'Email',
                ],
                'first_name' => [
                    'source' => 'body_after_label',
                    'label' => 'First Name',
                ],
            ],
            'required_fields' => [
                'email',
                'first_name',
            ],
        ]);

        $message = app(RecordInboundEmailAction::class)->handle(
            provider: 'resend',
            providerEventId: 'evt-missing-email',
            providerMessageId: 'msg-missing-email',
            from: 'Vendor <notifications@vendor.example>',
            toAddresses: ['website-leads@inbound.example.test'],
            text: 'First Name: Jane',
            html: null,
        );

        $message->refresh();

        $this->assertDatabaseCount('contacts', 0);
        $this->assertNull($message->related_contact_id);
        $this->assertSame(
            InboundMessage::CONTACT_EXTRACTION_FAILED,
            $message->contact_extraction_status,
        );
        $this->assertNotSame(
            '',
            trim((string) $message->contact_extraction_error),
        );
        $this->assertSame(InboundMessage::INBOX_STATUS_NEW, $message->inbox_status);

        $routeEvent = AutomationEventOutboxEvent::query()
            ->where(
                'event_key',
                RecordInboundMessageAction::ROUTED_EMAIL_AUTOMATION_EVENT_KEY,
            )
            ->sole();

        $this->assertNull($routeEvent->contact_id);
        $this->assertSame($route->key, data_get(
            $routeEvent->payload,
            'inbound_message.inbound_email_route_key',
        ));
    }

    public function test_reply_to_source_and_normalized_html_body_are_supported(): void
    {
        $this->route([
            'fields' => [
                'email' => [
                    'source' => 'reply_to_email',
                    'label' => null,
                ],
                'name' => [
                    'source' => 'body_after_label',
                    'label' => 'Name',
                ],
            ],
            'required_fields' => [
                'email',
                'name',
            ],
        ]);

        $message = app(RecordInboundEmailAction::class)->handle(
            provider: 'resend',
            providerEventId: 'evt-html-lead',
            providerMessageId: 'msg-html-lead',
            from: 'Vendor <notifications@vendor.example>',
            toAddresses: ['website-leads@inbound.example.test'],
            text: null,
            html: '<p>New lead</p><p>Name: Jane Doe</p>',
            replyTo: 'Jane Doe <JANE@EXAMPLE.COM>',
        );

        $contact = Contact::query()
            ->where('email', 'jane@example.com')
            ->sole();

        $this->assertSame('jane@example.com', $message->reply_to_value);
        $this->assertStringContainsString(
            "Name: Jane Doe",
            (string) $message->body,
        );
        $this->assertSame('Jane Doe', $contact->name);
        $this->assertSame($contact->getKey(), $message->related_contact_id);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function route(array $definition): InboundEmailRoute
    {
        return InboundEmailRoute::query()->create([
            'key' => 'website_leads',
            'local_part' => 'website-leads',
            'label' => 'Website Leads',
            'source' => 'crm',
            'context_key' => null,
            'is_active' => true,
            'contact_extraction_enabled' => true,
            'contact_extraction_definition' => [
                'version' => 1,
                ...$definition,
            ],
        ]);
    }
}
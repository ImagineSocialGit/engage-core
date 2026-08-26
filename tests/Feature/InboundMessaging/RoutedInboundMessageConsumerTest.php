<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\Email\RecordInboundEmailAction;
use App\Modules\InboundMessaging\Contracts\RoutedInboundMessageConsumer;
use App\Modules\InboundMessaging\Data\InboundEmailRouteIdentity;
use App\Modules\InboundMessaging\Data\RoutedInboundMessageConsumeResult;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Email\InboundEmailRouteWorkspace;
use App\Modules\InboundMessaging\Services\Email\RoutedInboundMessageConsumerRegistry;
use App\Modules\InboundMessaging\Validation\InboundMessagingSetupValidationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutedInboundMessageConsumerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set(
            'messaging.email.inbound_domain',
            'inbound.example.test',
        );
    }

    public function test_claimed_named_address_is_consumed_and_can_link_related_contact(): void
    {
        $route = $this->route();
        $contact = Contact::factory()->create();

        $consumer = new FakeRoutedInboundMessageConsumer(
            key: 'vendor.intake',
            label: 'Vendor Intake',
            routeKey: $route->key,
            result: RoutedInboundMessageConsumeResult::handled($contact),
        );

        $this->useConsumers([$consumer]);

        $message = $this->recordVendorMessage(
            eventId: 'evt_vendor_handled',
            messageId: 'msg_vendor_handled',
        )->refresh();

        $this->assertCount(1, $consumer->consumed);
        $this->assertSame(
            'Vendor update body.',
            $consumer->consumed[0]['body'],
        );
        $this->assertSame(
            $route->key,
            $consumer->consumed[0]['route_key'],
        );
        $this->assertSame($contact->getKey(), $message->related_contact_id);
        $this->assertNotNull($message->processed_at);
        $this->assertSame(
            InboundMessage::INBOX_STATUS_NEW,
            $message->inbox_status,
        );
        $this->assertNull($message->sender_id);
        $this->assertSame(
            'vendor@example.test',
            $message->from_value,
        );
    }

    public function test_unresolved_consumer_keeps_message_available_for_human_review(): void
    {
        $route = $this->route();

        $consumer = new FakeRoutedInboundMessageConsumer(
            key: 'vendor.intake',
            label: 'Vendor Intake',
            routeKey: $route->key,
            result: RoutedInboundMessageConsumeResult::unresolved(),
        );

        $this->useConsumers([$consumer]);

        $message = $this->recordVendorMessage(
            eventId: 'evt_vendor_unresolved',
            messageId: 'msg_vendor_unresolved',
        )->refresh();

        $this->assertCount(1, $consumer->consumed);
        $this->assertNull($message->processed_at);
        $this->assertNull($message->related_contact_id);
        $this->assertSame(
            InboundMessage::INBOX_STATUS_NEW,
            $message->inbox_status,
        );
    }

    public function test_unclaimed_named_address_remains_inbox_only(): void
    {
        $this->route();
        $this->useConsumers([]);

        $message = $this->recordVendorMessage(
            eventId: 'evt_vendor_inbox_only',
            messageId: 'msg_vendor_inbox_only',
        )->refresh();

        $this->assertNull($message->processed_at);
        $this->assertNull($message->related_contact_id);
        $this->assertSame(
            InboundMessage::INBOX_STATUS_NEW,
            $message->inbox_status,
        );
    }

    public function test_multiple_consumers_for_one_address_fail_setup_validation(): void
    {
        $route = $this->route();

        $this->useConsumers([
            new FakeRoutedInboundMessageConsumer(
                key: 'vendor.first',
                label: 'First Vendor Process',
                routeKey: $route->key,
                result: RoutedInboundMessageConsumeResult::handled(),
            ),
            new FakeRoutedInboundMessageConsumer(
                key: 'vendor.second',
                label: 'Second Vendor Process',
                routeKey: $route->key,
                result: RoutedInboundMessageConsumeResult::handled(),
            ),
        ]);

        $codes = collect(
            app(InboundMessagingSetupValidationContributor::class)
                ->findings(),
        )
            ->pluck('code')
            ->values()
            ->all();

        $this->assertContains(
            'inbound_messaging.email_routes.consumer_conflict',
            $codes,
        );
    }

    public function test_workspace_presents_connected_process_without_exposing_consumer_key(): void
    {
        $route = $this->route();

        $this->useConsumers([
            new FakeRoutedInboundMessageConsumer(
                key: 'vendor.internal_key',
                label: 'Vendor Intake',
                routeKey: $route->key,
                result: RoutedInboundMessageConsumeResult::handled(),
            ),
        ]);

        $workspace = app(InboundEmailRouteWorkspace::class)->build();
        $handling = $workspace['routes'][0]['handling'];

        $this->assertSame('connected', $handling['status']);
        $this->assertSame('Vendor Intake', $handling['label']);
        $this->assertArrayNotHasKey('consumer_key', $handling);
        $this->assertArrayNotHasKey('route_key', $handling);
    }

    private function route(): InboundEmailRoute
    {
        return InboundEmailRoute::query()->create([
            'key' => 'vendor_updates',
            'local_part' => 'vendor-updates',
            'label' => 'Vendor Updates',
            'source' => 'integration',
            'context_key' => 'vendor_update',
            'is_active' => true,
        ]);
    }

    private function recordVendorMessage(
        string $eventId,
        string $messageId,
    ): InboundMessage {
        return app(RecordInboundEmailAction::class)->handle(
            provider: 'resend',
            providerEventId: $eventId,
            providerMessageId: $messageId,
            from: 'Vendor <vendor@example.test>',
            toAddresses: [
                'vendor-updates@inbound.example.test',
            ],
            text: 'Vendor update body.',
            html: null,
            subject: 'Vendor update',
            messageId: '<'.$messageId.'@example.test>',
            receivedAt: now(),
        );
    }

    /**
     * @param array<int, RoutedInboundMessageConsumer> $consumers
     */
    private function useConsumers(array $consumers): void
    {
        $this->app->instance(
            RoutedInboundMessageConsumerRegistry::class,
            new RoutedInboundMessageConsumerRegistry($consumers),
        );
    }
}

final class FakeRoutedInboundMessageConsumer
    implements RoutedInboundMessageConsumer
{
    /**
     * @var array<int, array{message_id: int, body: ?string, route_key: string}>
     */
    public array $consumed = [];

    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $routeKey,
        private readonly RoutedInboundMessageConsumeResult $result,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function claims(InboundEmailRouteIdentity $route): bool
    {
        return $route->routeKey === $this->routeKey;
    }

    public function consume(
        InboundMessage $message,
        InboundEmailRouteIdentity $route,
    ): RoutedInboundMessageConsumeResult {
        $this->consumed[] = [
            'message_id' => (int) $message->getKey(),
            'body' => $message->body,
            'route_key' => $route->routeKey,
        ];

        return $this->result;
    }
}
<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Modules\Commerce\Providers\CommerceModuleServiceProvider;
use App\Modules\Commerce\Services\CommerceContactPurchaseHistoryReadService;
use App\Modules\Core\Data\Contacts\ContactPanel;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceContactPurchaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-29 15:00:00 UTC');
        $this->enableCommerce();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_commerce_registers_a_module_filtered_purchase_history_panel(): void
    {
        $contact = Contact::factory()->create();
        $order = $this->order(
            contact: $contact,
            externalId: 'order-panel',
            orderedAt: CarbonImmutable::parse('2026-09-28 17:00:00 UTC'),
        );

        $registry = app(ContactPanelRegistry::class);
        $panel = $registry->panelsFor($contact)->first(
            fn (ContactPanel $candidate): bool => $candidate->key === 'commerce-purchase-history',
        );

        $this->assertNotNull(
            $this->app->getProvider(CommerceModuleServiceProvider::class),
        );
        $this->assertInstanceOf(ContactPanel::class, $panel);
        $this->assertSame('crm.contacts.panels.commerce-purchase-history', $panel->view);
        $this->assertSame('commerce', $panel->module);
        $this->assertSame(80, $panel->sort);
        $this->assertSame(1, $panel->data['purchaseHistory']['order_count']);
        $this->assertSame((int) $order->getKey(), $panel->data['purchaseHistory']['recent_orders']->first()['id']);

        config()->set('modules.enabled', ['core']);

        $this->assertFalse(
            $registry->panelsFor($contact)->contains(
                fn (ContactPanel $candidate): bool =>
                    $candidate->key === 'commerce-purchase-history',
            ),
        );
    }

    public function test_history_includes_direct_and_customer_linked_orders_with_bounded_recent_rows(): void
    {
        $contact = Contact::factory()->create();
        $otherContact = Contact::factory()->create();
        $customer = CommerceCustomer::factory()->create([
            'contact_id' => $contact->getKey(),
            'provider' => 'shopify',
            'external_id' => 'customer-history',
        ]);
        $orders = collect();

        for ($index = 1; $index <= 6; $index++) {
            $orders->push($this->order(
                contact: $contact,
                externalId: 'order-direct-'.$index,
                orderedAt: CarbonImmutable::parse("2026-09-2{$index} 12:00:00 UTC"),
                totalCents: 1000 * $index,
            ));
        }

        $legacy = CommerceOrder::factory()->create([
            'commerce_customer_id' => $customer->getKey(),
            'contact_id' => null,
            'order_name' => '#LEGACY',
            'ordered_at' => CarbonImmutable::parse('2026-09-27 12:00:00 UTC'),
            'provider' => 'shopify',
            'external_id' => 'order-legacy',
            'currency' => 'USD',
            'total_cents' => 7000,
        ]);
        $orders->push($legacy);

        $this->confirmPurchase($orders[5]);
        $this->confirmPurchase($legacy);

        $other = $this->order(
            contact: $otherContact,
            externalId: 'order-other-contact',
            orderedAt: CarbonImmutable::parse('2026-09-29 12:00:00 UTC'),
            totalCents: 9900,
        );
        $this->confirmPurchase($other);

        $history = app(CommerceContactPurchaseHistoryReadService::class)
            ->forContact($contact);

        $this->assertSame(7, $history['order_count']);
        $this->assertSame(2, $history['confirmed_purchase_count']);
        $this->assertSame(1, $history['confirmed_values']->count());
        $this->assertEqualsCanonicalizing([
            'currency' => 'USD',
            'total_cents' => 13000,
        ], $history['confirmed_values']->first());
        $this->assertCount(5, $history['recent_orders']);
        $this->assertEquals(
            [
                $legacy->getKey(),
                $orders[5]->getKey(),
                $orders[4]->getKey(),
                $orders[3]->getKey(),
                $orders[2]->getKey(),
            ],
            $history['recent_orders']->modelKeys(),
        );
        $this->assertFalse($history['recent_orders']->contains(
            fn (CommerceOrder $order): bool => $order->is($other),
        ));
    }

    public function test_contact_show_renders_recent_purchase_history_and_order_links(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'Commerce Buyer',
        ]);
        $emptyContact = Contact::factory()->create([
            'name' => 'No Purchases',
        ]);
        $otherContact = Contact::factory()->create();
        $order = $this->order(
            contact: $contact,
            externalId: 'order-rendered',
            orderedAt: CarbonImmutable::parse('2026-09-28 18:30:00 UTC'),
            totalCents: 4500,
        );
        CommerceOrderItem::factory()
            ->for($order, 'commerceOrder')
            ->create([
                'name' => 'Tour Hoodie',
                'title' => 'Tour Hoodie',
                'quantity' => 1,
                'total_cents' => 4500,
            ]);
        $this->confirmPurchase($order);

        $other = $this->order(
            contact: $otherContact,
            externalId: 'order-private-other-contact',
            orderedAt: CarbonImmutable::parse('2026-09-29 10:00:00 UTC'),
        );

        $this->actingAs($user)
            ->get(route('crm.contacts.show', $contact))
            ->assertOk()
            ->assertSee('data-module-panel="commerce"', false)
            ->assertSee('data-commerce-purchase-history', false)
            ->assertSee('data-commerce-order-count="1"', false)
            ->assertSee('data-commerce-confirmed-count="1"', false)
            ->assertSee('data-commerce-order-id="'.$order->id.'"', false)
            ->assertSee(route('crm.commerce.orders.show', $order), false)
            ->assertSee('Tour Hoodie')
            ->assertDontSee('data-commerce-order-id="'.$other->id.'"', false);

        $this->actingAs($user)
            ->get(route('crm.contacts.show', $emptyContact))
            ->assertOk()
            ->assertDontSee('data-commerce-purchase-history', false);
    }

    private function enableCommerce(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'commerce',
        ])));

        if (! $this->app->getProvider(CommerceModuleServiceProvider::class)) {
            $this->app->register(CommerceModuleServiceProvider::class);
        }
    }

    private function order(
        Contact $contact,
        string $externalId,
        CarbonImmutable $orderedAt,
        int $totalCents = 2500,
    ): CommerceOrder {
        return CommerceOrder::factory()
            ->forContact($contact)
            ->create([
                'order_number' => str_replace('order-', '', $externalId),
                'order_name' => '#'.strtoupper(str_replace('order-', '', $externalId)),
                'status' => CommerceOrder::STATUS_CLOSED,
                'financial_status' => CommerceOrder::FINANCIAL_STATUS_PAID,
                'fulfillment_status' => CommerceOrder::FULFILLMENT_STATUS_FULFILLED,
                'currency' => 'USD',
                'subtotal_cents' => $totalCents,
                'total_cents' => $totalCents,
                'ordered_at' => $orderedAt,
                'closed_at' => $orderedAt,
                'provider' => 'shopify',
                'external_id' => $externalId,
            ]);
    }

    private function confirmPurchase(CommerceOrder $order): CommerceOrderEvent
    {
        return CommerceOrderEvent::factory()->create([
            'commerce_order_id' => $order->getKey(),
            'event' => CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'occurred_at' => $order->ordered_at,
            'source' => 'commerce',
            'provider' => $order->provider,
            'external_id' => 'purchase-confirmed-'.$order->getKey(),
            'payload' => null,
            'meta' => ['outcome_version' => 1],
        ]);
    }
}
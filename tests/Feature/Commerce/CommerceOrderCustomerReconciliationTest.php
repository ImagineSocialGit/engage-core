<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Contracts\CommerceOrderProvider;
use App\Modules\Commerce\Data\CommerceOrderCustomerSnapshotData;
use App\Modules\Commerce\Data\CommerceOrderSnapshotData;
use App\Modules\Commerce\Data\CommerceOrderSyncRequest;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Services\CommerceInventoryEffectOrchestrator;
use App\Modules\Commerce\Services\CommerceInventoryEffectRecorder;
use App\Modules\Commerce\Services\CommerceOrderCustomerReconciler;
use App\Modules\Commerce\Services\CommerceOrderInventoryEffectProducer;
use App\Modules\Commerce\Services\CommerceOrderSyncService;
use App\Modules\Commerce\Services\CommercePurchaseConfirmationService;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use App\Modules\Commerce\Services\CommerceProviderVariantReferenceResolver;
use App\Modules\Core\Models\Contact;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CommerceOrderCustomerReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_customer_reuses_existing_contact_without_overwriting_contact_profile(): void
    {
        $contact = Contact::factory()->create([
            'name' => 'CRM Name',
            'email' => 'buyer@example.com',
            'phone' => '+16135550100',
            'source' => 'crm',
            'subsource' => null,
        ]);

        $result = $this->service(new CustomerOrderFixtureProvider([
            'order-500' => $this->snapshot(
                new CommerceOrderCustomerSnapshotData(
                    externalId: 'customer-100',
                    firstName: 'Shopify',
                    lastName: 'Buyer',
                    name: 'Shopify Buyer',
                    email: 'Buyer@Example.com',
                    phone: '+16135550200',
                    currency: 'usd',
                    totalOrders: 4,
                    totalSpentCents: 12500,
                    externalUrl: 'https://example.test/customers/100',
                    meta: ['provider_updated_at' => '2026-09-28T17:00:00Z'],
                ),
            ),
        ]))->sync(new CommerceOrderSyncRequest('order-500'));

        $customer = CommerceCustomer::query()->firstOrFail();
        $order = CommerceOrder::query()->findOrFail($result->commerceOrderId);

        $this->assertSame((int) $contact->getKey(), (int) $customer->contact_id);
        $this->assertSame((int) $customer->getKey(), (int) $order->commerce_customer_id);
        $this->assertSame((int) $contact->getKey(), (int) $order->contact_id);
        $this->assertSame('customer-100', $customer->external_id);
        $this->assertSame('buyer@example.com', $customer->email);
        $this->assertSame('Shopify Buyer', $customer->name);
        $this->assertSame('USD', $customer->currency);
        $this->assertSame(4, $customer->total_orders);
        $this->assertSame(12500, $customer->total_spent_cents);
        $this->assertSame(
            '2026-09-24T19:00:00+00:00',
            $customer->first_ordered_at?->format(DATE_ATOM),
        );
        $this->assertSame(
            '2026-09-24T19:00:00+00:00',
            $customer->last_ordered_at?->format(DATE_ATOM),
        );
        $this->assertNull($customer->raw_payload);

        $contact->refresh();

        $this->assertSame('CRM Name', $contact->name);
        $this->assertSame('+16135550100', $contact->phone);
        $this->assertSame('crm', $contact->source);
    }

    public function test_new_provider_customer_creates_one_contact_and_repeat_sync_reuses_both_identities(): void
    {
        $provider = new CustomerOrderFixtureProvider([
            'order-500' => $this->snapshot(
                new CommerceOrderCustomerSnapshotData(
                    externalId: 'customer-100',
                    name: 'New Buyer',
                    email: 'new-buyer@example.com',
                    phone: '(615) 555-1212',
                    currency: 'USD',
                    totalOrders: 1,
                    totalSpentCents: 5000,
                ),
            ),
        ]);

        $service = $this->service($provider);
        $first = $service->sync(new CommerceOrderSyncRequest('order-500'));
        $second = $service->sync(new CommerceOrderSyncRequest('order-500'));

        $this->assertSame($first->commerceOrderId, $second->commerceOrderId);
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(1, CommerceCustomer::query()->count());

        $contact = Contact::query()->firstOrFail();
        $customer = CommerceCustomer::query()->firstOrFail();
        $order = CommerceOrder::query()->firstOrFail();

        $this->assertSame('new-buyer@example.com', $contact->email);
        $this->assertSame('New Buyer', $contact->name);
        $this->assertSame('+16155551212', $contact->phone);
        $this->assertSame('commerce', $contact->source);
        $this->assertSame('order-provider', $contact->subsource);
        $this->assertSame('order-provider', $contact->meta['commerce_provider']);
        $this->assertSame('customer-100', $contact->meta['commerce_customer_external_id']);
        $this->assertSame((int) $contact->getKey(), (int) $customer->contact_id);
        $this->assertSame((int) $contact->getKey(), (int) $order->contact_id);
        $this->assertSame((int) $customer->getKey(), (int) $order->commerce_customer_id);
    }

    public function test_existing_customer_contact_link_is_preserved_when_provider_email_changes(): void
    {
        $linked = Contact::factory()->create([
            'email' => 'linked@example.com',
            'name' => 'Linked Contact',
        ]);
        $other = Contact::factory()->create([
            'email' => 'new-address@example.com',
            'name' => 'Other Contact',
        ]);

        $customer = CommerceCustomer::factory()->create([
            'contact_id' => $linked->getKey(),
            'provider' => 'order-provider',
            'external_id' => 'customer-100',
            'email' => 'linked@example.com',
        ]);

        $this->service(new CustomerOrderFixtureProvider([
            'order-500' => $this->snapshot(
                new CommerceOrderCustomerSnapshotData(
                    externalId: 'customer-100',
                    name: 'Provider Profile',
                    email: 'new-address@example.com',
                    totalOrders: 2,
                    totalSpentCents: 9000,
                ),
            ),
        ]))->sync(new CommerceOrderSyncRequest('order-500'));

        $customer->refresh();
        $order = CommerceOrder::query()->firstOrFail();

        $this->assertSame((int) $linked->getKey(), (int) $customer->contact_id);
        $this->assertSame((int) $linked->getKey(), (int) $order->contact_id);
        $this->assertSame('new-address@example.com', $customer->email);
        $this->assertSame(2, Contact::query()->count());
        $this->assertSame('Other Contact', $other->fresh()?->name);
    }

    public function test_guest_order_email_links_contact_without_creating_commerce_customer(): void
    {
        $provider = new CustomerOrderFixtureProvider([
            'order-500' => $this->snapshot(
                new CommerceOrderCustomerSnapshotData(
                    externalId: null,
                    name: 'Guest Buyer',
                    email: 'guest@example.com',
                    phone: '+16155550000',
                ),
            ),
        ]);

        $service = $this->service($provider);
        $service->sync(new CommerceOrderSyncRequest('order-500'));
        $service->sync(new CommerceOrderSyncRequest('order-500'));

        $this->assertSame(0, CommerceCustomer::query()->count());
        $this->assertSame(1, Contact::query()->count());

        $contact = Contact::query()->firstOrFail();
        $order = CommerceOrder::query()->firstOrFail();

        $this->assertNull($order->commerce_customer_id);
        $this->assertSame((int) $contact->getKey(), (int) $order->contact_id);
        $this->assertSame('guest@example.com', $contact->email);
    }

    public function test_provider_customer_without_email_is_persisted_without_guessing_a_contact(): void
    {
        $this->service(new CustomerOrderFixtureProvider([
            'order-500' => $this->snapshot(
                new CommerceOrderCustomerSnapshotData(
                    externalId: 'customer-100',
                    name: 'No Email Buyer',
                    email: null,
                    totalOrders: 1,
                    totalSpentCents: 5000,
                ),
            ),
        ]))->sync(new CommerceOrderSyncRequest('order-500'));

        $customer = CommerceCustomer::query()->firstOrFail();
        $order = CommerceOrder::query()->firstOrFail();

        $this->assertNull($customer->contact_id);
        $this->assertNull($order->contact_id);
        $this->assertSame((int) $customer->getKey(), (int) $order->commerce_customer_id);
        $this->assertSame(0, Contact::query()->count());
    }

    private function service(
        CommerceOrderProvider $provider,
    ): CommerceOrderSyncService {
        $resolver = new CommerceProviderRoleResolver(
            providers: new CommerceProviderRegistry([$provider]),
            config: new Repository([
                'commerce' => [
                    'provider_roles' => [
                        CommerceProviderRole::Orders->value => [
                            'default' => $provider->key(),
                            'scopes' => [],
                        ],
                    ],
                ],
            ]),
        );

        return new CommerceOrderSyncService(
            roles: $resolver,
            customers: app(CommerceOrderCustomerReconciler::class),
            inventoryEffects: new CommerceOrderInventoryEffectProducer(
                roles: $resolver,
                recorder: app(CommerceInventoryEffectRecorder::class),
                orchestrator: new CommerceInventoryEffectOrchestrator(
                    roles: $resolver,
                    references: new CommerceProviderVariantReferenceResolver(),
                ),
            ),
            purchaseConfirmations: app(CommercePurchaseConfirmationService::class),
        );
    }

    private function snapshot(
        CommerceOrderCustomerSnapshotData $customer,
    ): CommerceOrderSnapshotData {
        return new CommerceOrderSnapshotData(
            externalId: 'order-500',
            orderNumber: '500',
            orderName: '#500',
            status: CommerceOrder::STATUS_OPEN,
            financialStatus: CommerceOrder::FINANCIAL_STATUS_PAID,
            fulfillmentStatus: CommerceOrder::FULFILLMENT_STATUS_UNFULFILLED,
            currency: 'USD',
            subtotalCents: 5000,
            discountCents: 0,
            taxCents: 0,
            shippingCents: 0,
            totalCents: 5000,
            orderedAt: new DateTimeImmutable('2026-09-24T19:00:00Z'),
            closedAt: null,
            cancelledAt: null,
            refundedAt: null,
            externalUrl: 'https://example.test/orders/500',
            items: [],
            customer: $customer,
        );
    }
}

final class CustomerOrderFixtureProvider implements CommerceOrderProvider
{
    /** @param array<string, CommerceOrderSnapshotData> $snapshots */
    public function __construct(
        private readonly array $snapshots,
    ) {}

    public function key(): string
    {
        return 'order-provider';
    }

    public function order(
        string $externalOrderId,
        ?string $scope = null,
    ): CommerceOrderSnapshotData {
        $snapshot = $this->snapshots[$externalOrderId] ?? null;

        if (! $snapshot instanceof CommerceOrderSnapshotData) {
            throw new RuntimeException(
                "No fixture order snapshot exists for [{$externalOrderId}].",
            );
        }

        return $snapshot;
    }
}
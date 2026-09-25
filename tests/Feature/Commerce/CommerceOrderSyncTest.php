<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Contracts\CommerceOrderProvider;
use App\Modules\Commerce\Data\CommerceOrderItemSnapshotData;
use App\Modules\Commerce\Data\CommerceOrderSnapshotData;
use App\Modules\Commerce\Data\CommerceOrderSyncRequest;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductProviderMapping;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Modules\Commerce\Services\CommerceOrderSyncService;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CommerceOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_sync_creates_order_items_and_resolves_canonical_identity_by_provider_mapping(): void
    {
        $mappedProduct = CommerceProduct::factory()->active()->create();
        $mappedVariant = CommerceProductVariant::factory()
            ->for($mappedProduct, 'commerceProduct')
            ->active()
            ->create([
                'sku' => 'SAME-SKU',
            ]);

        $unrelatedProduct = CommerceProduct::factory()->active()->create();
        CommerceProductVariant::factory()
            ->for($unrelatedProduct, 'commerceProduct')
            ->active()
            ->create([
                'sku' => 'SAME-SKU',
            ]);

        CommerceProductProviderMapping::query()->create([
            'commerce_product_id' => $mappedProduct->getKey(),
            'provider_key' => 'order-provider',
            'reference_type' => 'catalog_product',
            'external_id' => 'product-100',
            'status' => CommerceProductProviderMapping::STATUS_ACTIVE,
        ]);

        CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $mappedVariant->getKey(),
            'provider_key' => 'order-provider',
            'reference_type' => 'product_variant',
            'external_id' => 'variant-101',
            'external_parent_id' => 'product-100',
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
        ]);

        $provider = new OrderSyncFixtureProvider([
            'order-500' => $this->snapshot(
                name: '#500',
                items: [
                    $this->item(
                        externalId: 'line-1',
                        externalProductId: 'product-100',
                        externalVariantId: 'variant-101',
                        sku: 'SAME-SKU',
                        title: 'Tour Shirt',
                    ),
                ],
            ),
        ]);

        $result = $this->service($provider)->sync(
            new CommerceOrderSyncRequest(
                externalOrderId: 'order-500',
                event: CommerceOrderEvent::EVENT_CREATED,
                providerEventId: 'delivery-1',
                occurredAt: new DateTimeImmutable('2026-09-24T20:00:00Z'),
                eventMeta: [
                    'provider_event_type' => 'orders/create',
                ],
            ),
        );

        $this->assertTrue($result->orderCreated);
        $this->assertFalse($result->orderChanged);
        $this->assertFalse($result->orderUnchanged);
        $this->assertSame(1, $result->itemsCreated);
        $this->assertSame(0, $result->itemsChanged);
        $this->assertSame(0, $result->itemsUnchanged);
        $this->assertSame(0, $result->itemsRemoved);
        $this->assertTrue($result->eventCreated);

        $order = CommerceOrder::query()->findOrFail($result->commerceOrderId);

        $this->assertSame('order-provider', $order->provider);
        $this->assertSame('order-500', $order->external_id);
        $this->assertSame('#500', $order->order_name);
        $this->assertSame('USD', $order->currency);
        $this->assertSame(5000, $order->total_cents);

        $item = $order->items()->firstOrFail();

        $this->assertSame((int) $mappedProduct->getKey(), (int) $item->commerce_product_id);
        $this->assertSame((int) $mappedVariant->getKey(), (int) $item->commerce_product_variant_id);
        $this->assertSame('SAME-SKU', $item->sku);

        $event = $order->events()->firstOrFail();

        $this->assertSame(CommerceOrderEvent::EVENT_CREATED, $event->event);
        $this->assertSame('delivery-1', $event->external_id);
        $this->assertSame('orders/create', $event->meta['provider_event_type']);
        $this->assertNull($event->payload);

        $this->assertSame(['order-500'], $provider->requests);
    }

    public function test_repeat_authoritative_sync_reports_unchanged_without_duplicate_rows_or_event(): void
    {
        $provider = new OrderSyncFixtureProvider([
            'order-500' => $this->snapshot(
                name: '#500',
                items: [
                    $this->item(
                        externalId: 'line-1',
                        title: 'Tour Shirt',
                    ),
                ],
            ),
        ]);

        $service = $this->service($provider);
        $request = new CommerceOrderSyncRequest(
            externalOrderId: 'order-500',
            event: CommerceOrderEvent::EVENT_UPDATED,
            providerEventId: 'delivery-repeat',
        );

        $first = $service->sync($request);
        $second = $service->sync($request);

        $this->assertTrue($first->orderCreated);
        $this->assertTrue($first->eventCreated);

        $this->assertFalse($second->orderCreated);
        $this->assertFalse($second->orderChanged);
        $this->assertTrue($second->orderUnchanged);
        $this->assertSame(0, $second->itemsCreated);
        $this->assertSame(0, $second->itemsChanged);
        $this->assertSame(1, $second->itemsUnchanged);
        $this->assertFalse($second->eventCreated);

        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, CommerceOrderItem::query()->count());
        $this->assertSame(1, CommerceOrderEvent::query()->count());
    }

    public function test_later_snapshot_changes_existing_order_and_item_without_replacing_identity(): void
    {
        $provider = new OrderSyncFixtureProvider([
            'order-500' => $this->snapshot(
                name: '#500',
                items: [
                    $this->item(
                        externalId: 'line-1',
                        title: 'Original title',
                    ),
                ],
            ),
        ]);

        $service = $this->service($provider);

        $first = $service->sync(
            new CommerceOrderSyncRequest('order-500'),
        );

        $provider->snapshots['order-500'] = $this->snapshot(
            name: '#500 updated',
            totalCents: 6500,
            items: [
                $this->item(
                    externalId: 'line-1',
                    title: 'Updated title',
                    totalCents: 6500,
                ),
            ],
        );

        $second = $service->sync(
            new CommerceOrderSyncRequest('order-500'),
        );

        $this->assertFalse($second->orderCreated);
        $this->assertTrue($second->orderChanged);
        $this->assertFalse($second->orderUnchanged);
        $this->assertSame(0, $second->itemsCreated);
        $this->assertSame(1, $second->itemsChanged);
        $this->assertSame(0, $second->itemsUnchanged);

        $this->assertSame($first->commerceOrderId, $second->commerceOrderId);
        $this->assertSame(1, CommerceOrder::query()->count());
        $this->assertSame(1, CommerceOrderItem::query()->count());

        $order = CommerceOrder::query()->firstOrFail();

        $this->assertSame('#500 updated', $order->order_name);
        $this->assertSame(6500, $order->total_cents);
        $this->assertSame('Updated title', $order->items()->firstOrFail()->title);
    }

    public function test_complete_snapshot_soft_deletes_provider_items_that_disappear_and_restores_them_if_they_return(): void
    {
        $provider = new OrderSyncFixtureProvider([
            'order-500' => $this->snapshot(
                name: '#500',
                items: [
                    $this->item('line-1', title: 'First'),
                    $this->item('line-2', title: 'Second'),
                ],
            ),
        ]);

        $service = $this->service($provider);
        $service->sync(new CommerceOrderSyncRequest('order-500'));

        $provider->snapshots['order-500'] = $this->snapshot(
            name: '#500',
            items: [
                $this->item('line-1', title: 'First'),
            ],
        );

        $removed = $service->sync(
            new CommerceOrderSyncRequest('order-500'),
        );

        $this->assertSame(1, $removed->itemsRemoved);
        $this->assertSame(1, CommerceOrderItem::query()->count());
        $this->assertSame(2, CommerceOrderItem::withTrashed()->count());

        $provider->snapshots['order-500'] = $this->snapshot(
            name: '#500',
            items: [
                $this->item('line-1', title: 'First'),
                $this->item('line-2', title: 'Second restored'),
            ],
        );

        $restored = $service->sync(
            new CommerceOrderSyncRequest('order-500'),
        );

        $this->assertSame(0, $restored->itemsCreated);
        $this->assertSame(1, $restored->itemsChanged);
        $this->assertSame(1, $restored->itemsUnchanged);
        $this->assertSame(2, CommerceOrderItem::query()->count());
        $this->assertSame(
            'Second restored',
            CommerceOrderItem::query()
                ->where('external_id', 'line-2')
                ->firstOrFail()
                ->title,
        );
    }

    public function test_provider_event_identity_cannot_be_reused_for_a_different_order_event(): void
    {
        $provider = new OrderSyncFixtureProvider([
            'order-500' => $this->snapshot(
                name: '#500',
                items: [],
            ),
        ]);

        $service = $this->service($provider);

        $service->sync(new CommerceOrderSyncRequest(
            externalOrderId: 'order-500',
            event: CommerceOrderEvent::EVENT_CREATED,
            providerEventId: 'delivery-1',
        ));

        $this->expectException(RuntimeException::class);

        $service->sync(new CommerceOrderSyncRequest(
            externalOrderId: 'order-500',
            event: CommerceOrderEvent::EVENT_PAID,
            providerEventId: 'delivery-1',
        ));
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

        return new CommerceOrderSyncService($resolver);
    }

    /**
     * @param array<int, CommerceOrderItemSnapshotData> $items
     */
    private function snapshot(
        string $name,
        array $items,
        int $totalCents = 5000,
    ): CommerceOrderSnapshotData {
        return new CommerceOrderSnapshotData(
            externalId: 'order-500',
            orderNumber: '500',
            orderName: $name,
            status: CommerceOrder::STATUS_OPEN,
            financialStatus: CommerceOrder::FINANCIAL_STATUS_PAID,
            fulfillmentStatus: CommerceOrder::FULFILLMENT_STATUS_UNFULFILLED,
            currency: 'usd',
            subtotalCents: $totalCents,
            discountCents: 0,
            taxCents: 0,
            shippingCents: 0,
            totalCents: $totalCents,
            orderedAt: new DateTimeImmutable('2026-09-24T19:00:00Z'),
            closedAt: null,
            cancelledAt: null,
            refundedAt: null,
            externalUrl: 'https://example.test/orders/500',
            items: $items,
            meta: [
                'provider_updated_at' => '2026-09-24T20:00:00Z',
            ],
        );
    }

    private function item(
        string $externalId,
        ?string $externalProductId = null,
        ?string $externalVariantId = null,
        ?string $sku = null,
        ?string $title = null,
        int $totalCents = 5000,
    ): CommerceOrderItemSnapshotData {
        return new CommerceOrderItemSnapshotData(
            externalId: $externalId,
            externalProductId: $externalProductId,
            externalVariantId: $externalVariantId,
            sku: $sku,
            name: $title,
            title: $title,
            variantTitle: null,
            options: [],
            quantity: '1',
            currency: 'USD',
            unitPriceCents: $totalCents,
            discountCents: 0,
            taxCents: 0,
            totalCents: $totalCents,
            fulfillmentStatus: CommerceOrder::FULFILLMENT_STATUS_UNFULFILLED,
        );
    }
}

final class OrderSyncFixtureProvider implements CommerceOrderProvider
{
    /** @var array<int, string> */
    public array $requests = [];

    /**
     * @param array<string, CommerceOrderSnapshotData> $snapshots
     */
    public function __construct(
        public array $snapshots,
    ) {}

    public function key(): string
    {
        return 'order-provider';
    }

    public function order(
        string $externalOrderId,
        ?string $scope = null,
    ): CommerceOrderSnapshotData {
        $this->requests[] = $externalOrderId;

        $snapshot = $this->snapshots[$externalOrderId] ?? null;

        if (! $snapshot instanceof CommerceOrderSnapshotData) {
            throw new RuntimeException(
                "No fixture order snapshot exists for [{$externalOrderId}].",
            );
        }

        return $snapshot;
    }
}
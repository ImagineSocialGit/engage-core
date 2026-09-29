<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Contracts\CommerceOrderProvider;
use App\Modules\Commerce\Data\CommerceOrderItemSnapshotData;
use App\Modules\Commerce\Data\CommerceOrderSnapshotData;
use App\Modules\Commerce\Data\CommerceOrderSyncRequest;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Modules\Commerce\Services\CommerceOrderCustomerReconciler;
use App\Modules\Commerce\Services\CommerceOrderSyncService;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CommerceOrderSyncJsonReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_key_order_does_not_make_repeat_snapshot_changed(): void
    {
        $provider = new JsonReplayOrderProvider($this->snapshot());
        $service = $this->service($provider);

        $first = $service->sync(new CommerceOrderSyncRequest('order-json-replay'));

        $this->assertTrue($first->orderCreated);
        $this->assertSame(1, $first->itemsCreated);

        $order = CommerceOrder::query()->findOrFail($first->commerceOrderId);
        $item = $order->items()->firstOrFail();
        $orderUpdatedAt = $order->updated_at?->copy();
        $itemUpdatedAt = $item->updated_at?->copy();

        $provider->snapshot = $this->snapshot(
            orderMeta: $this->reverseMap($order->meta),
            itemOptions: $this->reverseMap($item->options),
            itemMeta: $this->reverseMap($item->meta),
        );

        $second = $service->sync(new CommerceOrderSyncRequest('order-json-replay'));

        $this->assertFalse($second->orderCreated);
        $this->assertFalse($second->orderChanged);
        $this->assertTrue($second->orderUnchanged);
        $this->assertSame(0, $second->itemsCreated);
        $this->assertSame(0, $second->itemsChanged);
        $this->assertSame(1, $second->itemsUnchanged);
        $this->assertSame(0, $second->itemsRemoved);

        $order->refresh();
        $item->refresh();

        $this->assertEquals($orderUpdatedAt, $order->updated_at);
        $this->assertEquals($itemUpdatedAt, $item->updated_at);
    }

    public function test_actual_json_value_change_still_reports_order_and_item_changed(): void
    {
        $provider = new JsonReplayOrderProvider($this->snapshot());
        $service = $this->service($provider);

        $first = $service->sync(new CommerceOrderSyncRequest('order-json-replay'));
        $order = CommerceOrder::query()->findOrFail($first->commerceOrderId);
        $item = $order->items()->firstOrFail();

        $orderMeta = $this->reverseMap($order->meta);
        $orderMeta['shopify_updated_at'] = '2026-09-29T17:00:00Z';

        $itemMeta = $this->reverseMap($item->meta);
        $itemMeta['shopify_current_quantity'] = 2;

        $provider->snapshot = $this->snapshot(
            orderMeta: $orderMeta,
            itemOptions: $this->reverseMap($item->options),
            itemMeta: $itemMeta,
        );

        $second = $service->sync(new CommerceOrderSyncRequest('order-json-replay'));

        $this->assertFalse($second->orderCreated);
        $this->assertTrue($second->orderChanged);
        $this->assertFalse($second->orderUnchanged);
        $this->assertSame(0, $second->itemsCreated);
        $this->assertSame(1, $second->itemsChanged);
        $this->assertSame(0, $second->itemsUnchanged);
        $this->assertSame(0, $second->itemsRemoved);
    }

    private function service(
        CommerceOrderProvider $provider,
    ): CommerceOrderSyncService {
        return new CommerceOrderSyncService(
            roles: new CommerceProviderRoleResolver(
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
            ),
            customers: app(CommerceOrderCustomerReconciler::class),
        );
    }

    /**
     * @param array<string, mixed>|null $orderMeta
     * @param array<string, string>|null $itemOptions
     * @param array<string, mixed>|null $itemMeta
     */
    private function snapshot(
        ?array $orderMeta = null,
        ?array $itemOptions = null,
        ?array $itemMeta = null,
    ): CommerceOrderSnapshotData {
        return new CommerceOrderSnapshotData(
            externalId: 'order-json-replay',
            orderNumber: '500',
            orderName: '#500',
            status: CommerceOrder::STATUS_OPEN,
            financialStatus: CommerceOrder::FINANCIAL_STATUS_PAID,
            fulfillmentStatus: CommerceOrder::FULFILLMENT_STATUS_FULFILLED,
            currency: 'USD',
            subtotalCents: 2500,
            discountCents: 0,
            taxCents: 0,
            shippingCents: 0,
            totalCents: 2500,
            orderedAt: new DateTimeImmutable('2026-09-29T16:00:00Z'),
            closedAt: null,
            cancelledAt: null,
            refundedAt: null,
            externalUrl: 'https://example.test/orders/500',
            items: [
                new CommerceOrderItemSnapshotData(
                    externalId: 'line-json-replay',
                    sku: 'JSON-REPLAY',
                    name: 'Replay Shirt',
                    title: 'Replay Shirt',
                    variantTitle: 'Large',
                    options: $itemOptions ?? [
                        'Size' => 'Large',
                        'Color' => 'Black',
                    ],
                    quantity: '1',
                    currency: 'USD',
                    unitPriceCents: 2500,
                    totalCents: 2500,
                    fulfillmentStatus: CommerceOrder::FULFILLMENT_STATUS_FULFILLED,
                    meta: $itemMeta ?? [
                        'shopify_current_quantity' => 1,
                        'shopify_unfulfilled_quantity' => 0,
                        'tax_allocation' => 'order_only',
                    ],
                ),
            ],
            meta: $orderMeta ?? [
                'shopify_legacy_order_id' => '7112461877411',
                'shopify_financial_status' => 'PAID',
                'shopify_fulfillment_status' => 'FULFILLED',
                'shopify_closed' => true,
                'shopify_confirmation_number' => 'HQDKJ0Q0U',
                'shopify_updated_at' => '2026-09-29T16:32:00Z',
            ],
        );
    }

    /** @return array<string, mixed> */
    private function reverseMap(?array $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_reverse($value, true);
    }
}

final class JsonReplayOrderProvider implements CommerceOrderProvider
{
    public function __construct(
        public CommerceOrderSnapshotData $snapshot,
    ) {}

    public function key(): string
    {
        return 'json-replay-provider';
    }

    public function order(
        string $externalOrderId,
        ?string $scope = null,
    ): CommerceOrderSnapshotData {
        if ($externalOrderId !== $this->snapshot->externalId) {
            throw new RuntimeException(
                "No JSON replay fixture exists for [{$externalOrderId}].",
            );
        }

        return $this->snapshot;
    }
}
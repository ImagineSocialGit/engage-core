<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Contracts\CommerceOrderProvider;
use App\Modules\Commerce\Data\CommerceOrderItemSnapshotData;
use App\Modules\Commerce\Data\CommerceOrderSnapshotData;
use App\Modules\Commerce\Data\CommerceOrderSyncRequest;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Services\CommerceInventoryEffectOrchestrator;
use App\Modules\Commerce\Services\CommerceInventoryEffectRecorder;
use App\Modules\Commerce\Services\CommerceOrderCustomerReconciler;
use App\Modules\Commerce\Services\CommerceOrderInventoryEffectProducer;
use App\Modules\Commerce\Services\CommerceOrderSyncService;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use App\Modules\Commerce\Services\CommerceProviderVariantReferenceResolver;
use App\Modules\Commerce\Services\CommercePurchaseConfirmationService;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CommerceOrderSyncJsonReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_ignores_json_object_key_order_but_preserves_real_json_changes(): void
    {
        $provider = new JsonReplayOrderProvider([
            'order-json-replay' => $this->snapshot(
                orderMeta: [
                    'shopify_updated_at' => '2026-09-29T16:32:00Z',
                    'shopify_closed' => true,
                    'shopify_confirmation_number' => 'HQDKJ0Q0U',
                    'shopify_financial_status' => 'PAID',
                ],
                itemMeta: [
                    'tax_allocation' => 'order_only',
                    'shopify_unfulfilled_quantity' => 0,
                    'shopify_current_quantity' => 1,
                ],
            ),
        ]);

        $service = $this->service($provider);
        $first = $service->sync(
            new CommerceOrderSyncRequest('order-json-replay'),
        );

        $order = CommerceOrder::query()->findOrFail($first->commerceOrderId);
        $item = $order->items()->firstOrFail();

        $fixedUpdatedAt = '2026-09-01 00:00:00';

        DB::table('commerce_orders')
            ->where('id', $order->getKey())
            ->update([
                'meta' => json_encode([
                    'shopify_financial_status' => 'PAID',
                    'shopify_confirmation_number' => 'HQDKJ0Q0U',
                    'shopify_closed' => true,
                    'shopify_updated_at' => '2026-09-29T16:32:00Z',
                ], JSON_THROW_ON_ERROR),
                'updated_at' => $fixedUpdatedAt,
            ]);

        DB::table('commerce_order_items')
            ->where('id', $item->getKey())
            ->update([
                'meta' => json_encode([
                    'shopify_current_quantity' => 1,
                    'shopify_unfulfilled_quantity' => 0,
                    'tax_allocation' => 'order_only',
                ], JSON_THROW_ON_ERROR),
                'updated_at' => $fixedUpdatedAt,
            ]);

        $second = $service->sync(
            new CommerceOrderSyncRequest('order-json-replay'),
        );

        $this->assertFalse($second->orderCreated);
        $this->assertFalse($second->orderChanged);
        $this->assertTrue($second->orderUnchanged);
        $this->assertSame(0, $second->itemsCreated);
        $this->assertSame(0, $second->itemsChanged);
        $this->assertSame(1, $second->itemsUnchanged);
        $this->assertSame(
            $fixedUpdatedAt,
            DB::table('commerce_orders')
                ->where('id', $order->getKey())
                ->value('updated_at'),
        );
        $this->assertSame(
            $fixedUpdatedAt,
            DB::table('commerce_order_items')
                ->where('id', $item->getKey())
                ->value('updated_at'),
        );

        $provider->snapshots['order-json-replay'] = $this->snapshot(
            orderMeta: [
                'shopify_updated_at' => '2026-09-29T16:32:00Z',
                'shopify_closed' => false,
                'shopify_confirmation_number' => 'HQDKJ0Q0U',
                'shopify_financial_status' => 'PAID',
            ],
            itemMeta: [
                'tax_allocation' => 'order_only',
                'shopify_unfulfilled_quantity' => 0,
                'shopify_current_quantity' => 2,
            ],
        );

        $third = $service->sync(
            new CommerceOrderSyncRequest('order-json-replay'),
        );

        $this->assertTrue($third->orderChanged);
        $this->assertFalse($third->orderUnchanged);
        $this->assertSame(1, $third->itemsChanged);
        $this->assertSame(0, $third->itemsUnchanged);
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

    /**
     * @param array<string, mixed> $orderMeta
     * @param array<string, mixed> $itemMeta
     */
    private function snapshot(
        array $orderMeta,
        array $itemMeta,
    ): CommerceOrderSnapshotData {
        return new CommerceOrderSnapshotData(
            externalId: 'order-json-replay',
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
            items: [
                new CommerceOrderItemSnapshotData(
                    externalId: 'line-1',
                    sku: 'TOUR-S',
                    name: 'Tour Shirt',
                    title: 'Tour Shirt',
                    quantity: '1',
                    currency: 'USD',
                    unitPriceCents: 5000,
                    discountCents: 0,
                    taxCents: 0,
                    totalCents: 5000,
                    fulfillmentStatus: CommerceOrder::FULFILLMENT_STATUS_UNFULFILLED,
                    meta: $itemMeta,
                ),
            ],
            meta: $orderMeta,
        );
    }
}

final class JsonReplayOrderProvider implements CommerceOrderProvider
{
    /** @param array<string, CommerceOrderSnapshotData> $snapshots */
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
        $snapshot = $this->snapshots[$externalOrderId] ?? null;

        if (! $snapshot instanceof CommerceOrderSnapshotData) {
            throw new RuntimeException(
                "No fixture order snapshot exists for [{$externalOrderId}].",
            );
        }

        return $snapshot;
    }
}
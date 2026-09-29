<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Data\CommerceInventoryEffectData;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderItem;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use RuntimeException;

final class CommerceOrderInventoryEffectProducer
{
    private const SOURCE_TYPE = 'commerce_order_item';
    private const REASON = 'authoritative_order_consumption';

    public function __construct(
        private readonly CommerceProviderRoleResolver $roles,
        private readonly CommerceInventoryEffectRecorder $recorder,
        private readonly CommerceInventoryEffectOrchestrator $orchestrator,
    ) {}

    /**
     * Record only the order-consumption facts that can be proven from the current
     * authoritative order snapshot without inventing refund/restock semantics.
     *
     * @return array<int, int>
     */
    public function recordAuthoritativeConsumption(
        CommerceOrder $order,
        string $ordersProviderKey,
        ?string $inventoryScope = null,
        ?DateTimeInterface $occurredAt = null,
    ): array {
        if (! $order->exists || (int) $order->getKey() < 1) {
            throw new RuntimeException(
                'Commerce order inventory effects require a persisted order.',
            );
        }

        $ordersProviderKey = trim($ordersProviderKey);

        if ($ordersProviderKey === '') {
            throw new RuntimeException(
                'Commerce order inventory effects require an orders provider key.',
            );
        }

        $inventoryProvider = $this->roles->resolveOptional(
            CommerceProviderRole::Inventory,
            $inventoryScope,
        );

        if ($inventoryProvider === null
            || trim($inventoryProvider->key()) !== $ordersProviderKey
        ) {
            return [];
        }

        $effectIds = [];

        $items = CommerceOrderItem::query()
            ->where('commerce_order_id', $order->getKey())
            ->where('provider', $ordersProviderKey)
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            if ($item->commerce_product_variant_id === null) {
                continue;
            }

            $currentQuantity = $this->scaledNonNegativeQuantity(
                (string) $item->quantity,
            );

            if ($currentQuantity === 0) {
                continue;
            }

            $sourceKey = $this->sourceKey(
                providerKey: $ordersProviderKey,
                externalOrderId: (string) $order->external_id,
                externalOrderItemId: (string) $item->external_id,
            );

            $existing = CommerceInventoryEffect::query()
                ->where('source_type', self::SOURCE_TYPE)
                ->where('source_key', $sourceKey)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $recordedConsumption = 0;

            foreach ($existing as $effect) {
                $this->assertExistingEffectIdentity(
                    effect: $effect,
                    item: $item,
                );

                $recordedConsumption += $this->scaledConsumptionDelta(
                    (string) $effect->quantity_delta,
                );

                if (! in_array($effect->status, [
                    CommerceInventoryEffect::STATUS_RECONCILED,
                    CommerceInventoryEffect::STATUS_ADJUSTED,
                ], true)) {
                    $effectIds[] = (int) $effect->getKey();
                }
            }

            if ($currentQuantity <= $recordedConsumption) {
                continue;
            }

            $increment = $currentQuantity - $recordedConsumption;
            $consumedThrough = $this->decimalQuantity($currentQuantity);
            $effect = $this->recorder->record(
                new CommerceInventoryEffectData(
                    commerceProductVariantId: (int) $item->commerce_product_variant_id,
                    quantityDelta: '-'.$this->decimalQuantity($increment),
                    reason: self::REASON,
                    sourceType: self::SOURCE_TYPE,
                    sourceKey: $sourceKey,
                    idempotencyKey: $this->idempotencyKey(
                        providerKey: $ordersProviderKey,
                        externalOrderId: (string) $order->external_id,
                        externalOrderItemId: (string) $item->external_id,
                        consumedThroughQuantity: $consumedThrough,
                    ),
                    authorityMode: CommerceInventoryAuthorityMode::AuthorityAlreadyApplied,
                    sourceReference: (string) $order->external_id,
                    inventoryScope: $inventoryScope,
                    occurredAt: $occurredAt !== null
                        ? CarbonImmutable::instance($occurredAt)
                        : now()->toImmutable(),
                    meta: [
                        'orders_provider_key' => $ordersProviderKey,
                        'inventory_provider_key' => $ordersProviderKey,
                        'commerce_order_id' => (int) $order->getKey(),
                        'external_order_id' => (string) $order->external_id,
                        'commerce_order_item_id' => (int) $item->getKey(),
                        'external_order_item_id' => (string) $item->external_id,
                        'observed_quantity' => $this->decimalQuantity($currentQuantity),
                        'consumed_through_quantity' => $consumedThrough,
                    ],
                ),
            );

            $effectIds[] = (int) $effect->getKey();
        }

        return array_values(array_unique($effectIds));
    }

    /**
     * @param array<int, int> $effectIds
     */
    public function reconcileAuthoritativeEffects(array $effectIds): void
    {
        foreach (array_values(array_unique($effectIds)) as $effectId) {
            if (! is_int($effectId) || $effectId < 1) {
                throw new RuntimeException(
                    'Commerce order inventory effect identity is invalid.',
                );
            }

            $effect = CommerceInventoryEffect::query()->findOrFail($effectId);

            if ($effect->authority_mode
                !== CommerceInventoryAuthorityMode::AuthorityAlreadyApplied
            ) {
                throw new RuntimeException(
                    'Commerce authoritative order producer may only reconcile authority-already-applied effects.',
                );
            }

            $this->orchestrator->orchestrate($effect);
        }
    }

    private function assertExistingEffectIdentity(
        CommerceInventoryEffect $effect,
        CommerceOrderItem $item,
    ): void {
        if ((int) $effect->commerce_product_variant_id
                !== (int) $item->commerce_product_variant_id
            || $effect->reason !== self::REASON
            || $effect->authority_mode
                !== CommerceInventoryAuthorityMode::AuthorityAlreadyApplied
        ) {
            throw new RuntimeException(
                'Commerce authoritative order inventory effect identity conflicts with previously recorded evidence.',
            );
        }
    }

    private function sourceKey(
        string $providerKey,
        string $externalOrderId,
        string $externalOrderItemId,
    ): string {
        return 'order-item:'.hash(
            'sha256',
            implode('|', [
                trim($providerKey),
                trim($externalOrderId),
                trim($externalOrderItemId),
            ]),
        );
    }

    private function idempotencyKey(
        string $providerKey,
        string $externalOrderId,
        string $externalOrderItemId,
        string $consumedThroughQuantity,
    ): string {
        return 'commerce-order-consumption:'.hash(
            'sha256',
            implode('|', [
                trim($providerKey),
                trim($externalOrderId),
                trim($externalOrderItemId),
                $consumedThroughQuantity,
            ]),
        );
    }

    private function scaledNonNegativeQuantity(string $quantity): int
    {
        $quantity = trim($quantity);

        if (preg_match(
            '/^(\d{1,8})(?:\.(\d{1,4}))?$/D',
            $quantity,
            $matches,
        ) !== 1) {
            throw new RuntimeException(
                'Commerce order item quantity is invalid for inventory-effect production.',
            );
        }

        $fraction = str_pad($matches[2] ?? '', 4, '0');

        return ((int) $matches[1] * 10000) + (int) $fraction;
    }

    private function scaledConsumptionDelta(string $quantityDelta): int
    {
        $quantityDelta = trim($quantityDelta);

        if (preg_match(
            '/^-(\d{1,8})\.(\d{4})$/D',
            $quantityDelta,
            $matches,
        ) !== 1) {
            throw new RuntimeException(
                'Commerce authoritative order inventory effects must remain negative consumption deltas.',
            );
        }

        return ((int) $matches[1] * 10000) + (int) $matches[2];
    }

    private function decimalQuantity(int $scaledQuantity): string
    {
        if ($scaledQuantity < 0) {
            throw new RuntimeException(
                'Commerce inventory quantity scale cannot be negative.',
            );
        }

        return intdiv($scaledQuantity, 10000)
            .'.'
            .str_pad((string) ($scaledQuantity % 10000), 4, '0', STR_PAD_LEFT);
    }
}
<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Contracts\CommerceOrderProvider;
use App\Modules\Commerce\Data\CommerceOrderItemSnapshotData;
use App\Modules\Commerce\Data\CommerceOrderSnapshotData;
use App\Modules\Commerce\Data\CommerceOrderSyncRequest;
use App\Modules\Commerce\Data\CommerceOrderSyncResult;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductProviderMapping;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class CommerceOrderSyncService
{
    public function __construct(
        private readonly CommerceProviderRoleResolver $roles,
    ) {}

    public function sync(
        CommerceOrderSyncRequest $request,
    ): CommerceOrderSyncResult {
        $provider = $this->roles->resolve(
            CommerceProviderRole::Orders,
            $request->scope,
        );

        if (! $provider instanceof CommerceOrderProvider) {
            throw new RuntimeException(
                'Configured Commerce orders provider is invalid.',
            );
        }

        $providerKey = trim($provider->key());

        if ($providerKey === '') {
            throw new RuntimeException(
                'Commerce orders provider key cannot be empty.',
            );
        }

        $externalOrderId = trim($request->externalOrderId);
        $snapshot = $provider->order(
            externalOrderId: $externalOrderId,
            scope: $request->scope,
        );

        if (trim($snapshot->externalId) !== $externalOrderId) {
            throw new RuntimeException(
                'Commerce orders provider returned a snapshot for a different external order identity.',
            );
        }

        return DB::transaction(function () use (
            $providerKey,
            $request,
            $snapshot,
        ): CommerceOrderSyncResult {
            $order = CommerceOrder::withTrashed()
                ->where('provider', $providerKey)
                ->where('external_id', $snapshot->externalId)
                ->lockForUpdate()
                ->first();

            $orderCreated = ! $order instanceof CommerceOrder;

            if (! $order instanceof CommerceOrder) {
                $order = new CommerceOrder();
            } elseif ($order->trashed()) {
                $order->restore();
            }

            $previousStatus = $order->exists
                ? (string) $order->status
                : null;

            $order->fill([
                'order_number' => $this->nullableString($snapshot->orderNumber),
                'order_name' => $this->nullableString($snapshot->orderName),
                'status' => trim($snapshot->status),
                'financial_status' => $this->nullableString($snapshot->financialStatus),
                'fulfillment_status' => $this->nullableString($snapshot->fulfillmentStatus),
                'currency' => $this->currency($snapshot->currency),
                'subtotal_cents' => $snapshot->subtotalCents,
                'discount_cents' => $snapshot->discountCents,
                'tax_cents' => $snapshot->taxCents,
                'shipping_cents' => $snapshot->shippingCents,
                'total_cents' => $snapshot->totalCents,
                'ordered_at' => $snapshot->orderedAt,
                'closed_at' => $snapshot->closedAt,
                'cancelled_at' => $snapshot->cancelledAt,
                'refunded_at' => $snapshot->refundedAt,
                'source' => 'provider',
                'provider' => $providerKey,
                'external_id' => $snapshot->externalId,
                'external_url' => $this->nullableString($snapshot->externalUrl),
                'raw_payload' => null,
                'meta' => $snapshot->meta !== [] ? $snapshot->meta : null,
            ]);

            $orderChanged = ! $orderCreated && $order->isDirty();

            $order->save();

            $itemsCreated = 0;
            $itemsChanged = 0;
            $itemsUnchanged = 0;
            $seenExternalIds = [];

            foreach ($snapshot->items as $itemData) {
                $outcome = $this->persistItem(
                    providerKey: $providerKey,
                    order: $order,
                    data: $itemData,
                );

                $seenExternalIds[] = trim($itemData->externalId);

                $itemsCreated += $outcome === 'created' ? 1 : 0;
                $itemsChanged += $outcome === 'changed' ? 1 : 0;
                $itemsUnchanged += $outcome === 'unchanged' ? 1 : 0;
            }

            $itemsRemoved = $this->removeMissingItems(
                providerKey: $providerKey,
                order: $order,
                seenExternalIds: $seenExternalIds,
            );

            $eventCreated = $this->recordEvent(
                providerKey: $providerKey,
                order: $order,
                previousStatus: $previousStatus,
                request: $request,
            );

            return new CommerceOrderSyncResult(
                providerKey: $providerKey,
                commerceOrderId: (int) $order->getKey(),
                orderCreated: $orderCreated,
                orderChanged: $orderChanged,
                orderUnchanged: ! $orderCreated && ! $orderChanged,
                itemsCreated: $itemsCreated,
                itemsChanged: $itemsChanged,
                itemsUnchanged: $itemsUnchanged,
                itemsRemoved: $itemsRemoved,
                eventCreated: $eventCreated,
            );
        });
    }

    /**
     * @return 'created'|'changed'|'unchanged'
     */
    private function persistItem(
        string $providerKey,
        CommerceOrder $order,
        CommerceOrderItemSnapshotData $data,
    ): string {
        [$productId, $variantId] = $this->canonicalItemIdentity(
            providerKey: $providerKey,
            externalProductId: $data->externalProductId,
            externalVariantId: $data->externalVariantId,
        );

        $externalId = trim($data->externalId);

        $item = CommerceOrderItem::withTrashed()
            ->where('commerce_order_id', $order->getKey())
            ->where('provider', $providerKey)
            ->where('external_id', $externalId)
            ->lockForUpdate()
            ->first();

        $created = ! $item instanceof CommerceOrderItem;

        if (! $item instanceof CommerceOrderItem) {
            $item = new CommerceOrderItem([
                'commerce_order_id' => $order->getKey(),
            ]);
        } elseif ($item->trashed()) {
            $item->restore();
        }

        $item->fill([
            'commerce_order_id' => $order->getKey(),
            'commerce_product_id' => $productId,
            'commerce_product_variant_id' => $variantId,
            'item_type' => CommerceOrderItem::TYPE_PRODUCT,
            'sku' => $this->nullableString($data->sku),
            'name' => $this->nullableString($data->name),
            'title' => $this->nullableString($data->title),
            'variant_title' => $this->nullableString($data->variantTitle),
            'options' => $data->options !== [] ? $data->options : null,
            'quantity' => trim($data->quantity),
            'currency' => $this->currency($data->currency),
            'unit_price_cents' => $data->unitPriceCents,
            'discount_cents' => $data->discountCents,
            'tax_cents' => $data->taxCents,
            'total_cents' => $data->totalCents,
            'fulfillment_status' => $this->nullableString($data->fulfillmentStatus),
            'source' => 'provider',
            'provider' => $providerKey,
            'external_id' => $externalId,
            'external_product_id' => $this->nullableString($data->externalProductId),
            'external_variant_id' => $this->nullableString($data->externalVariantId),
            'external_url' => $this->nullableString($data->externalUrl),
            'raw_payload' => null,
            'meta' => $data->meta !== [] ? $data->meta : null,
        ]);

        $changed = ! $created && $item->isDirty();

        $item->save();

        return match (true) {
            $created => 'created',
            $changed => 'changed',
            default => 'unchanged',
        };
    }

    /**
     * @return array{0:?int,1:?int}
     */
    private function canonicalItemIdentity(
        string $providerKey,
        ?string $externalProductId,
        ?string $externalVariantId,
    ): array {
        $productId = null;
        $variantId = null;

        $normalizedVariantId = $this->nullableString($externalVariantId);

        if ($normalizedVariantId !== null) {
            $variantMapping = CommerceProductVariantProviderMapping::query()
                ->where('provider_key', $providerKey)
                ->where('reference_type', 'product_variant')
                ->where('external_id', $normalizedVariantId)
                ->first();

            if ($variantMapping instanceof CommerceProductVariantProviderMapping) {
                $variant = CommerceProductVariant::withTrashed()->find(
                    $variantMapping->commerce_product_variant_id,
                );

                if (! $variant instanceof CommerceProductVariant) {
                    throw new RuntimeException(
                        'Commerce order item variant mapping references a missing canonical variant.',
                    );
                }

                $variantId = (int) $variant->getKey();
                $productId = (int) $variant->commerce_product_id;
            }
        }

        $normalizedProductId = $this->nullableString($externalProductId);

        if ($normalizedProductId !== null) {
            $productMapping = CommerceProductProviderMapping::query()
                ->where('provider_key', $providerKey)
                ->where('reference_type', 'catalog_product')
                ->where('external_id', $normalizedProductId)
                ->first();

            if ($productMapping instanceof CommerceProductProviderMapping) {
                $product = CommerceProduct::withTrashed()->find(
                    $productMapping->commerce_product_id,
                );

                if (! $product instanceof CommerceProduct) {
                    throw new RuntimeException(
                        'Commerce order item product mapping references a missing canonical product.',
                    );
                }

                if ($productId !== null
                    && $productId !== (int) $product->getKey()
                ) {
                    throw new RuntimeException(
                        'Commerce order item provider product and variant mappings disagree.',
                    );
                }

                $productId = (int) $product->getKey();
            }
        }

        return [$productId, $variantId];
    }

    /**
     * @param array<int, string> $seenExternalIds
     */
    private function removeMissingItems(
        string $providerKey,
        CommerceOrder $order,
        array $seenExternalIds,
    ): int {
        $query = CommerceOrderItem::query()
            ->where('commerce_order_id', $order->getKey())
            ->where('provider', $providerKey);

        if ($seenExternalIds !== []) {
            $query->whereNotIn('external_id', array_values(array_unique($seenExternalIds)));
        }

        $removed = 0;

        foreach ($query->get() as $item) {
            $item->delete();
            $removed++;
        }

        return $removed;
    }

    private function recordEvent(
        string $providerKey,
        CommerceOrder $order,
        ?string $previousStatus,
        CommerceOrderSyncRequest $request,
    ): bool {
        $providerEventId = $this->nullableString($request->providerEventId);

        if ($providerEventId === null) {
            return false;
        }

        $event = CommerceOrderEvent::withTrashed()
            ->where('provider', $providerKey)
            ->where('external_id', $providerEventId)
            ->lockForUpdate()
            ->first();

        if ($event instanceof CommerceOrderEvent) {
            if ((int) $event->commerce_order_id !== (int) $order->getKey()
                || (string) $event->event !== $request->event
            ) {
                throw new RuntimeException(
                    'Commerce provider event identity was reused for a different order reconciliation event.',
                );
            }

            if ($event->trashed()) {
                $event->restore();
            }

            return false;
        }

        CommerceOrderEvent::query()->create([
            'commerce_order_id' => $order->getKey(),
            'event' => $request->event,
            'from_status' => $previousStatus,
            'to_status' => $order->status,
            'occurred_at' => $request->occurredAt ?? now(),
            'source' => 'provider',
            'provider' => $providerKey,
            'external_id' => $providerEventId,
            'payload' => null,
            'meta' => $request->eventMeta !== []
                ? $request->eventMeta
                : null,
        ]);

        return true;
    }

    private function nullableString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function currency(?string $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        $value = strtoupper($value);

        if (preg_match('/^[A-Z]{3}$/D', $value) !== 1) {
            throw new InvalidArgumentException(
                'Commerce order currency must be a three-letter code.',
            );
        }

        return $value;
    }
}
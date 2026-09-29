<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Data\CommercePurchaseConfirmation;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use Carbon\CarbonImmutable;
use RuntimeException;

final class CommercePurchaseConfirmationReader
{
    public function forOrder(int $commerceOrderId): ?CommercePurchaseConfirmation
    {
        if ($commerceOrderId < 1) {
            throw new RuntimeException(
                'Commerce purchase confirmation lookup requires a valid order identity.',
            );
        }

        $event = CommerceOrderEvent::query()
            ->where('commerce_order_id', $commerceOrderId)
            ->where('event', CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED)
            ->where('source', 'commerce')
            ->orderBy('id')
            ->first();

        return $event instanceof CommerceOrderEvent
            ? $this->fromEvent($event)
            : null;
    }

    public function fromEvent(
        CommerceOrderEvent $event,
    ): CommercePurchaseConfirmation {
        if ($event->event !== CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED
            || $event->source !== 'commerce'
        ) {
            throw new RuntimeException(
                'Commerce purchase confirmation reader received a non-confirmation order event.',
            );
        }

        $payload = $event->payload;

        if (! is_array($payload)) {
            throw new RuntimeException(
                'Commerce purchase confirmation event payload is missing.',
            );
        }

        $commerceOrderId = $this->positiveInteger(
            $payload['commerce_order_id'] ?? null,
            'commerce_order_id',
        );
        $commerceOrderItemIds = $this->positiveIntegerList(
            $payload['commerce_order_item_ids'] ?? null,
            'commerce_order_item_ids',
        );
        $contactId = $this->nullablePositiveInteger(
            $payload['contact_id'] ?? null,
            'contact_id',
        );
        $commerceCustomerId = $this->nullablePositiveInteger(
            $payload['commerce_customer_id'] ?? null,
            'commerce_customer_id',
        );
        $providerKey = $this->requiredString(
            $payload['provider_key'] ?? null,
            'provider_key',
        );
        $source = $this->requiredString(
            $payload['source'] ?? null,
            'source',
        );
        $externalOrderId = $this->requiredString(
            $payload['external_order_id'] ?? null,
            'external_order_id',
        );
        $occurredAt = $event->occurred_at?->toImmutable();

        if (! $occurredAt instanceof CarbonImmutable) {
            throw new RuntimeException(
                'Commerce purchase confirmation event occurrence time is missing.',
            );
        }

        if ($commerceOrderId !== (int) $event->commerce_order_id) {
            throw new RuntimeException(
                'Commerce purchase confirmation event payload references a different order.',
            );
        }

        if ($providerKey !== trim((string) $event->provider)) {
            throw new RuntimeException(
                'Commerce purchase confirmation event provider evidence is inconsistent.',
            );
        }

        return new CommercePurchaseConfirmation(
            commerceOrderId: $commerceOrderId,
            commerceOrderItemIds: $commerceOrderItemIds,
            contactId: $contactId,
            commerceCustomerId: $commerceCustomerId,
            providerKey: $providerKey,
            source: $source,
            externalOrderId: $externalOrderId,
            occurredAt: $occurredAt,
        );
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 1) {
            throw new RuntimeException(
                "Commerce purchase confirmation field [{$field}] is invalid.",
            );
        }

        return $value;
    }

    private function nullablePositiveInteger(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        return $this->positiveInteger($value, $field);
    }

    /**
     * @return array<int, int>
     */
    private function positiveIntegerList(mixed $value, string $field): array
    {
        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            throw new RuntimeException(
                "Commerce purchase confirmation field [{$field}] is invalid.",
            );
        }

        $ids = [];

        foreach ($value as $item) {
            $id = $this->positiveInteger($item, $field);

            if (in_array($id, $ids, true)) {
                throw new RuntimeException(
                    "Commerce purchase confirmation field [{$field}] contains duplicate identities.",
                );
            }

            $ids[] = $id;
        }

        return $ids;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException(
                "Commerce purchase confirmation field [{$field}] is invalid.",
            );
        }

        return trim($value);
    }
}
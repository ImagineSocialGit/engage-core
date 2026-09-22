<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Data\CommerceInventoryEffectData;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use InvalidArgumentException;
use RuntimeException;

final class CommerceInventoryEffectRecorder
{
    public function record(CommerceInventoryEffectData $data): CommerceInventoryEffect
    {
        $quantityDelta = $this->normalizeQuantity($data->quantityDelta);
        $reason = $this->required($data->reason, 'reason', 120);
        $sourceType = $this->required($data->sourceType, 'source type', 80);
        $sourceKey = $this->required($data->sourceKey, 'source key', 120);
        $idempotencyKey = $this->required($data->idempotencyKey, 'idempotency key', 191);
        $sourceReference = $this->nullable($data->sourceReference, 'source reference', 255);
        $inventoryScope = $this->nullable($data->inventoryScope, 'inventory scope', 120);

        if ($data->commerceProductVariantId <= 0) {
            throw new InvalidArgumentException(
                'Commerce inventory effects require a persisted product variant.',
            );
        }

        $fingerprint = hash('sha256', json_encode([
            'commerce_product_variant_id' => $data->commerceProductVariantId,
            'quantity_delta' => $quantityDelta,
            'reason' => $reason,
            'source_type' => $sourceType,
            'source_key' => $sourceKey,
            'source_reference' => $sourceReference,
            'authority_mode' => $data->authorityMode->value,
            'inventory_scope' => $inventoryScope,
        ], JSON_THROW_ON_ERROR));

        $effect = CommerceInventoryEffect::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'commerce_product_variant_id' => $data->commerceProductVariantId,
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
                'source_reference' => $sourceReference,
                'reason' => $reason,
                'quantity_delta' => $quantityDelta,
                'authority_mode' => $data->authorityMode,
                'inventory_scope' => $inventoryScope,
                'status' => CommerceInventoryEffect::STATUS_RECORDED,
                'payload_fingerprint' => $fingerprint,
                'occurred_at' => $data->occurredAt ?? now(),
                'meta' => $data->meta !== [] ? $data->meta : null,
            ],
        );

        if (! hash_equals((string) $effect->payload_fingerprint, $fingerprint)) {
            throw new RuntimeException(
                "Commerce inventory effect idempotency key [{$idempotencyKey}] was reused for a different effect.",
            );
        }

        return $effect;
    }

    private function normalizeQuantity(string $quantity): string
    {
        $quantity = trim($quantity);

        if (! preg_match('/^-?\d{1,8}(?:\.\d{1,4})?$/', $quantity)) {
            throw new InvalidArgumentException(
                'Commerce inventory quantity delta must fit a decimal(12,4) value.',
            );
        }

        $negative = str_starts_with($quantity, '-');
        $unsigned = $negative ? substr($quantity, 1) : $quantity;
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');

        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, 4, '0');

        $normalized = $whole.'.'.$fraction;

        if ($normalized === '0.0000') {
            throw new InvalidArgumentException(
                'Commerce inventory quantity delta cannot be zero.',
            );
        }

        return $negative ? '-'.$normalized : $normalized;
    }

    private function required(string $value, string $field, int $maxLength): string
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                "Commerce inventory effect {$field} cannot be empty.",
            );
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                "Commerce inventory effect {$field} exceeds the supported length.",
            );
        }

        return $value;
    }

    private function nullable(
        ?string $value,
        string $field,
        int $maxLength,
    ): ?string {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                "Commerce inventory effect {$field} exceeds the supported length.",
            );
        }

        return $value;
    }
}
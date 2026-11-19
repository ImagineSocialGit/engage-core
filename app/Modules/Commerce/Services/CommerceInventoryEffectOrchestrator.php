<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Contracts\CommerceInventoryAdjustmentProvider;
use App\Modules\Commerce\Contracts\CommerceInventoryLocationProvider;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentRequest;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentResult;
use App\Modules\Commerce\Data\CommerceInventoryOrchestrationResult;
use App\Modules\Commerce\Data\CommerceProviderReferenceData;
use App\Modules\Commerce\Data\CommerceProviderVariantReference;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryAdjustment;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CommerceInventoryEffectOrchestrator
{
    private const CLAIM_TTL_SECONDS = 300;

    public function __construct(
        private readonly CommerceProviderRoleResolver $roles,
        private readonly CommerceProviderVariantReferenceResolver $references,
    ) {}

    public function orchestrate(
        CommerceInventoryEffect $effect,
    ): CommerceInventoryOrchestrationResult {
        if (! $effect->exists || (int) $effect->getKey() < 1) {
            throw new RuntimeException(
                'Commerce inventory orchestration requires a persisted inventory effect.',
            );
        }

        $effect = CommerceInventoryEffect::query()->findOrFail($effect->getKey());

        return match ($effect->authority_mode) {
            CommerceInventoryAuthorityMode::AuthorityAlreadyApplied => $this->reconcileWithoutMutation($effect),
            CommerceInventoryAuthorityMode::AuthorityOperationPending => $this->awaitAuthorityOperation($effect),
            CommerceInventoryAuthorityMode::AdjustmentRequired => $this->adjustAuthority($effect),
        };
    }

    public function confirmAuthorityOperationApplied(
        CommerceInventoryEffect $effect,
    ): CommerceInventoryOrchestrationResult {
        if (! $effect->exists || (int) $effect->getKey() < 1) {
            throw new RuntimeException(
                'Commerce inventory authority-operation confirmation requires a persisted inventory effect.',
            );
        }

        return DB::transaction(function () use ($effect): CommerceInventoryOrchestrationResult {
            $locked = CommerceInventoryEffect::query()
                ->lockForUpdate()
                ->findOrFail($effect->getKey());

            if ($locked->authority_mode !== CommerceInventoryAuthorityMode::AuthorityOperationPending) {
                throw new RuntimeException(
                    'Only Commerce inventory effects awaiting an authority operation may be confirmed through this path.',
                );
            }

            if ($locked->adjustments()->exists()) {
                throw new RuntimeException(
                    'Commerce inventory effect awaiting another authority operation cannot own an outbound adjustment.',
                );
            }

            if ($locked->status !== CommerceInventoryEffect::STATUS_RECONCILED) {
                $locked->forceFill([
                    'status' => CommerceInventoryEffect::STATUS_RECONCILED,
                ])->save();
            }

            return new CommerceInventoryOrchestrationResult(
                commerceInventoryEffectId: (int) $locked->getKey(),
                outcome: CommerceInventoryOrchestrationResult::OUTCOME_RECONCILED,
                effectStatus: $locked->status,
                providerMutationAttempted: false,
            );
        });
    }

    private function reconcileWithoutMutation(
        CommerceInventoryEffect $effect,
    ): CommerceInventoryOrchestrationResult {
        return DB::transaction(function () use ($effect): CommerceInventoryOrchestrationResult {
            $locked = CommerceInventoryEffect::query()
                ->lockForUpdate()
                ->findOrFail($effect->getKey());

            if ($locked->adjustments()->exists()) {
                throw new RuntimeException(
                    'Commerce inventory effect marked authority-already-applied cannot own an outbound adjustment.',
                );
            }

            if ($locked->status !== CommerceInventoryEffect::STATUS_RECONCILED) {
                $locked->forceFill([
                    'status' => CommerceInventoryEffect::STATUS_RECONCILED,
                ])->save();
            }

            return new CommerceInventoryOrchestrationResult(
                commerceInventoryEffectId: (int) $locked->getKey(),
                outcome: CommerceInventoryOrchestrationResult::OUTCOME_RECONCILED,
                effectStatus: $locked->status,
                providerMutationAttempted: false,
            );
        });
    }

    private function awaitAuthorityOperation(
        CommerceInventoryEffect $effect,
    ): CommerceInventoryOrchestrationResult {
        $effect = CommerceInventoryEffect::query()->findOrFail($effect->getKey());

        if ($effect->adjustments()->exists()) {
            throw new RuntimeException(
                'Commerce inventory effect awaiting another authority operation cannot own an outbound adjustment.',
            );
        }

        return new CommerceInventoryOrchestrationResult(
            commerceInventoryEffectId: (int) $effect->getKey(),
            outcome: CommerceInventoryOrchestrationResult::OUTCOME_AWAITING_AUTHORITY_OPERATION,
            effectStatus: $effect->status,
            providerMutationAttempted: false,
        );
    }

    private function adjustAuthority(
        CommerceInventoryEffect $effect,
    ): CommerceInventoryOrchestrationResult {
        try {
            $provider = $this->roles->resolve(
                CommerceProviderRole::Inventory,
                $effect->inventory_scope,
            );

            if (! $provider instanceof CommerceInventoryAdjustmentProvider) {
                throw new RuntimeException(
                    "Commerce inventory provider [{$provider->key()}] does not support authoritative inventory adjustments.",
                );
            }

            $locationId = $provider instanceof CommerceInventoryLocationProvider
                ? $provider->inventoryLocationId($effect->inventory_scope)
                : null;

            if ($locationId !== null && trim($locationId) === '') {
                throw new RuntimeException('Commerce inventory provider returned a blank location identity.');
            }

            $claim = $this->claimAdjustment(
                effectId: (int) $effect->getKey(),
                providerKey: $provider->key(),
                locationId: $locationId,
            );
        } catch (Throwable $exception) {
            $this->markEffectFailed((int) $effect->getKey(), $exception);

            throw $exception;
        }

        /** @var CommerceInventoryAdjustment $adjustment */
        $adjustment = $claim['adjustment'];
        /** @var CommerceInventoryEffect $claimedEffect */
        $claimedEffect = $claim['effect'];

        if (! $claim['claimed']) {
            $outcome = $adjustment->status === CommerceInventoryAdjustment::STATUS_SUCCEEDED
                ? CommerceInventoryOrchestrationResult::OUTCOME_ADJUSTED
                : CommerceInventoryOrchestrationResult::OUTCOME_ADJUSTMENT_IN_PROGRESS;

            return new CommerceInventoryOrchestrationResult(
                commerceInventoryEffectId: (int) $claimedEffect->getKey(),
                outcome: $outcome,
                effectStatus: $claimedEffect->status,
                providerMutationAttempted: false,
                providerKey: $provider->key(),
                commerceInventoryAdjustmentId: (int) $adjustment->getKey(),
                adjustmentStatus: $adjustment->status,
            );
        }

        // Retries use the immutable destination captured before the first
        // provider attempt, not the current mutable catalog mapping.
        $request = new CommerceInventoryAdjustmentRequest(
            commerceInventoryEffectId: (int) $claimedEffect->getKey(),
            variant: $claim['reference'],
            quantityDelta: (string) $claimedEffect->quantity_delta,
            reason: (string) $claimedEffect->reason,
            idempotencyKey: (string) $adjustment->idempotency_key,
            sourceType: (string) $claimedEffect->source_type,
            sourceKey: (string) $claimedEffect->source_key,
            sourceReference: $claimedEffect->source_reference,
            inventoryScope: $claimedEffect->inventory_scope,
            occurredAt: $claimedEffect->occurred_at,
            meta: is_array($claimedEffect->meta) ? $claimedEffect->meta : [],
            inventoryLocationId: $claim['location_id'],
        );

        try {
            $result = $provider->adjustInventory($request);
            $this->validateProviderResult(
                providerKey: $provider->key(),
                adjustment: $adjustment,
                result: $result,
            );

            return $this->completeAdjustment(
                effectId: (int) $claimedEffect->getKey(),
                adjustmentId: (int) $adjustment->getKey(),
                result: $result,
            );
        } catch (Throwable $exception) {
            $this->failAdjustment(
                effectId: (int) $claimedEffect->getKey(),
                adjustmentId: (int) $adjustment->getKey(),
                attempt: (int) $claim['attempt'],
                exception: $exception,
            );

            throw $exception;
        }
    }

    /**
     * @return array{
     *     effect: CommerceInventoryEffect,
     *     adjustment: CommerceInventoryAdjustment,
     *     claimed: bool,
     *     attempt: int,
     *     reference: ?CommerceProviderVariantReference,
     *     location_id: ?string
     * }
     */
    private function claimAdjustment(
        int $effectId,
        string $providerKey,
        ?string $locationId,
    ): array {
        return DB::transaction(function () use ($effectId, $providerKey, $locationId): array {
            $effect = CommerceInventoryEffect::query()
                ->lockForUpdate()
                ->findOrFail($effectId);

            if ($effect->authority_mode !== CommerceInventoryAuthorityMode::AdjustmentRequired) {
                throw new RuntimeException(
                    'Commerce inventory effect authority mode changed before adjustment could be claimed.',
                );
            }

            $idempotencyKey = $this->adjustmentIdempotencyKey(
                providerKey: $providerKey,
                effectIdempotencyKey: (string) $effect->idempotency_key,
            );

            // An inventory effect can have only one authority destination.
            // A role binding change must never create a second adjustment for
            // an effect that was already sent to another provider.
            $existing = CommerceInventoryAdjustment::query()
                ->where('commerce_inventory_effect_id', $effect->getKey())
                ->lockForUpdate()
                ->get();

            if ($existing->count() > 1) {
                throw new RuntimeException(
                    'Commerce inventory effect owns multiple outbound adjustments and requires reconciliation.',
                );
            }

            $adjustment = $existing->first();

            if (! $adjustment instanceof CommerceInventoryAdjustment) {
                $destination = $this->captureDestination($effect, $providerKey, $locationId);

                $adjustment = CommerceInventoryAdjustment::query()->create([
                    'commerce_inventory_effect_id' => $effect->getKey(),
                    'commerce_product_variant_provider_mapping_id' => $destination['mapping_id'],
                    'provider_key' => $providerKey,
                    'quantity_delta' => $effect->quantity_delta,
                    'status' => CommerceInventoryAdjustment::STATUS_PENDING,
                    'idempotency_key' => $idempotencyKey,
                    'attempts' => 0,
                    'meta' => ['destination' => $destination['snapshot']],
                ]);
            }

            $this->assertAdjustmentIdentity(
                effect: $effect,
                adjustment: $adjustment,
                providerKey: $providerKey,
            );

            if ($adjustment->status === CommerceInventoryAdjustment::STATUS_SUCCEEDED) {
                if ($effect->status !== CommerceInventoryEffect::STATUS_ADJUSTED) {
                    $effect->forceFill([
                        'status' => CommerceInventoryEffect::STATUS_ADJUSTED,
                    ])->save();
                }

                return [
                    'effect' => $effect,
                    'adjustment' => $adjustment,
                    'claimed' => false,
                    'attempt' => (int) $adjustment->attempts,
                    'reference' => null,
                    'location_id' => $locationId,
                ];
            }

            if ($adjustment->status === CommerceInventoryAdjustment::STATUS_PROCESSING
                && $adjustment->claimed_at !== null
                && $adjustment->claimed_at->isAfter(
                    now()->subSeconds(self::CLAIM_TTL_SECONDS),
                )
            ) {
                return [
                    'effect' => $effect,
                    'adjustment' => $adjustment,
                    'claimed' => false,
                    'attempt' => (int) $adjustment->attempts,
                    'reference' => null,
                    'location_id' => $locationId,
                ];
            }

            $destination = is_array($adjustment->meta)
                ? ($adjustment->meta['destination'] ?? null)
                : null;

            if (! is_array($destination)) {
                // An older attempt may have reached the authority before
                // crashing. Never infer its destination from today's mapping.
                if ((int) $adjustment->attempts > 0) {
                    throw new RuntimeException(
                        'Commerce inventory adjustment has an unpinned legacy destination; manual reconciliation is required.',
                    );
                }

                $captured = $this->captureDestination($effect, $providerKey, $locationId);
                $destination = $captured['snapshot'];
                $adjustment->forceFill([
                    'commerce_product_variant_provider_mapping_id' => $captured['mapping_id'],
                    'meta' => array_merge(
                        is_array($adjustment->meta) ? $adjustment->meta : [],
                        ['destination' => $destination],
                    ),
                ])->save();
            }

            // Location-aware integrations must never retry a previously
            // attempted adjustment without an immutable location pin. For
            // never-attempted legacy rows we can safely record that pin now.
            if ($locationId !== null && ! array_key_exists('inventory_location_id', $destination)) {
                if ((int) $adjustment->attempts > 0) {
                    throw new RuntimeException(
                        'Commerce inventory adjustment has an unpinned legacy location; manual reconciliation is required.',
                    );
                }

                $destination['inventory_location_id'] = $locationId;
                $adjustment->forceFill([
                    'meta' => array_merge(
                        is_array($adjustment->meta) ? $adjustment->meta : [],
                        ['destination' => $destination],
                    ),
                ])->save();
            }

            if (($destination['inventory_location_id'] ?? null) !== $locationId) {
                throw new RuntimeException(
                    'Commerce inventory adjustment location changed after destination pinning; reconciliation is required.',
                );
            }

            $reference = $this->restoreDestination($effect, $providerKey, $destination);
            $attempt = ((int) $adjustment->attempts) + 1;
            $now = now();

            $adjustment->forceFill([
                'status' => CommerceInventoryAdjustment::STATUS_PROCESSING,
                'attempts' => $attempt,
                'requested_at' => $adjustment->requested_at ?? $now,
                'claimed_at' => $now,
                'completed_at' => null,
                'failure_reason' => null,
            ])->save();

            if ($effect->status === CommerceInventoryEffect::STATUS_FAILED) {
                $effect->forceFill([
                    'status' => CommerceInventoryEffect::STATUS_RECORDED,
                ])->save();
            }

            return [
                'effect' => $effect,
                'adjustment' => $adjustment,
                'claimed' => true,
                'attempt' => $attempt,
                'reference' => $reference,
                'location_id' => $locationId,
            ];
        });
    }

    private function completeAdjustment(
        int $effectId,
        int $adjustmentId,
        CommerceInventoryAdjustmentResult $result,
    ): CommerceInventoryOrchestrationResult {
        return DB::transaction(function () use (
            $effectId,
            $adjustmentId,
            $result,
        ): CommerceInventoryOrchestrationResult {
            $effect = CommerceInventoryEffect::query()
                ->lockForUpdate()
                ->findOrFail($effectId);
            $adjustment = CommerceInventoryAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($adjustmentId);

            if ($adjustment->status !== CommerceInventoryAdjustment::STATUS_SUCCEEDED) {
                $adjustment->forceFill([
                    'status' => CommerceInventoryAdjustment::STATUS_SUCCEEDED,
                    'external_id' => $this->nullableString($result->externalId),
                    'completed_at' => $result->completedAt ?? now(),
                    'claimed_at' => null,
                    'failure_reason' => null,
                    'meta' => array_merge(
                        is_array($adjustment->meta) ? $adjustment->meta : [],
                        $result->meta,
                        ['destination' => $adjustment->meta['destination'] ?? null],
                    ),
                ])->save();
            }

            if ($effect->status !== CommerceInventoryEffect::STATUS_ADJUSTED) {
                $effect->forceFill([
                    'status' => CommerceInventoryEffect::STATUS_ADJUSTED,
                ])->save();
            }

            return new CommerceInventoryOrchestrationResult(
                commerceInventoryEffectId: (int) $effect->getKey(),
                outcome: CommerceInventoryOrchestrationResult::OUTCOME_ADJUSTED,
                effectStatus: $effect->status,
                providerMutationAttempted: true,
                providerKey: $adjustment->provider_key,
                commerceInventoryAdjustmentId: (int) $adjustment->getKey(),
                adjustmentStatus: $adjustment->status,
            );
        });
    }

    private function failAdjustment(
        int $effectId,
        int $adjustmentId,
        int $attempt,
        Throwable $exception,
    ): void {
        DB::transaction(function () use (
            $effectId,
            $adjustmentId,
            $attempt,
            $exception,
        ): void {
            $effect = CommerceInventoryEffect::query()
                ->lockForUpdate()
                ->findOrFail($effectId);
            $adjustment = CommerceInventoryAdjustment::query()
                ->lockForUpdate()
                ->findOrFail($adjustmentId);

            if ($adjustment->status === CommerceInventoryAdjustment::STATUS_SUCCEEDED) {
                return;
            }

            if ($adjustment->status !== CommerceInventoryAdjustment::STATUS_PROCESSING
                || (int) $adjustment->attempts !== $attempt
            ) {
                return;
            }

            $adjustment->forceFill([
                'status' => CommerceInventoryAdjustment::STATUS_FAILED,
                'claimed_at' => null,
                'failure_reason' => mb_substr($exception->getMessage(), 0, 4000),
            ])->save();

            if (! in_array($effect->status, [
                CommerceInventoryEffect::STATUS_ADJUSTED,
                CommerceInventoryEffect::STATUS_RECONCILED,
            ], true)) {
                $effect->forceFill([
                    'status' => CommerceInventoryEffect::STATUS_FAILED,
                ])->save();
            }
        });
    }

    private function markEffectFailed(int $effectId, Throwable $exception): void
    {
        DB::transaction(function () use ($effectId, $exception): void {
            $effect = CommerceInventoryEffect::query()
                ->lockForUpdate()
                ->findOrFail($effectId);

            if (in_array($effect->status, [
                CommerceInventoryEffect::STATUS_ADJUSTED,
                CommerceInventoryEffect::STATUS_RECONCILED,
            ], true)) {
                return;
            }

            $adjustment = CommerceInventoryAdjustment::query()
                ->where('commerce_inventory_effect_id', $effectId)
                ->lockForUpdate()
                ->first();

            if ($adjustment instanceof CommerceInventoryAdjustment
                && in_array($adjustment->status, [
                    CommerceInventoryAdjustment::STATUS_PENDING,
                    CommerceInventoryAdjustment::STATUS_FAILED,
                ], true)
            ) {
                $adjustment->forceFill([
                    'status' => CommerceInventoryAdjustment::STATUS_FAILED,
                    'failure_reason' => mb_substr($exception->getMessage(), 0, 4000),
                ])->save();
            }

            $effect->forceFill([
                'status' => CommerceInventoryEffect::STATUS_FAILED,
            ])->save();
        });
    }

    private function validateProviderResult(
        string $providerKey,
        CommerceInventoryAdjustment $adjustment,
        CommerceInventoryAdjustmentResult $result,
    ): void {
        if ($result->providerKey !== $providerKey) {
            throw new RuntimeException(
                'Commerce inventory adjustment result provider identity does not match the resolved provider.',
            );
        }

        if ($result->idempotencyKey !== $adjustment->idempotency_key) {
            throw new RuntimeException(
                'Commerce inventory adjustment result idempotency identity does not match the requested adjustment.',
            );
        }
    }

    private function assertAdjustmentIdentity(
        CommerceInventoryEffect $effect,
        CommerceInventoryAdjustment $adjustment,
        string $providerKey,
    ): void {
        if ((int) $adjustment->commerce_inventory_effect_id !== (int) $effect->getKey()
            || $adjustment->provider_key !== $providerKey
            || (string) $adjustment->quantity_delta !== (string) $effect->quantity_delta
            || $adjustment->idempotency_key !== $this->adjustmentIdempotencyKey(
                providerKey: $providerKey,
                effectIdempotencyKey: (string) $effect->idempotency_key,
            )
        ) {
            throw new RuntimeException(
                'Commerce inventory adjustment idempotency identity was reused for a different effect.',
            );
        }
    }

    /**
     * Pin all provider references before any outbound attempt. The mapping ID
     * is only a convenient audit link; the immutable identities live in meta.
     *
     * @return array{mapping_id: int, snapshot: array<string, mixed>}
     */
    private function captureDestination(
        CommerceInventoryEffect $effect,
        string $providerKey,
        ?string $locationId,
    ): array {
        $variant = CommerceProductVariant::query()->findOrFail(
            $effect->commerce_product_variant_id,
        );

        $mappings = CommerceProductVariantProviderMapping::query()
            ->where('commerce_product_variant_id', $variant->getKey())
            ->where('provider_key', $providerKey)
            ->where('status', CommerceProductVariantProviderMapping::STATUS_ACTIVE)
            ->orderBy('reference_type')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($mappings->isEmpty()) {
            throw new RuntimeException(
                'Commerce variant has no active explicit mapping for the requested provider.',
            );
        }

        // Preserve the existing canonical reference contract for all providers.
        $resolved = $this->references->resolve($variant, $providerKey);

        $references = $mappings->map(
            static fn (CommerceProductVariantProviderMapping $mapping): array => [
                'mapping_id' => (int) $mapping->getKey(),
                'reference_type' => $mapping->reference_type,
                'external_id' => $mapping->external_id,
                'external_parent_id' => $mapping->external_parent_id,
                'external_url' => $mapping->external_url,
                'meta' => $mapping->meta,
            ],
        )->all();

        return [
            'mapping_id' => (int) $mappings->first()->getKey(),
            'snapshot' => [
                'provider_key' => $providerKey,
                'commerce_product_id' => (int) $variant->commerce_product_id,
                'commerce_product_variant_id' => (int) $variant->getKey(),
                'inventory_scope' => $effect->inventory_scope,
                'inventory_location_id' => $locationId,
                'sku' => $resolved->sku,
                'barcode' => $resolved->barcode,
                'references' => $references,
            ],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function restoreDestination(
        CommerceInventoryEffect $effect,
        string $providerKey,
        array $snapshot,
    ): CommerceProviderVariantReference {
        if (($snapshot['provider_key'] ?? null) !== $providerKey
            || ($snapshot['commerce_product_variant_id'] ?? null) !== (int) $effect->commerce_product_variant_id
            || ($snapshot['inventory_scope'] ?? null) !== $effect->inventory_scope
            || ! is_int($snapshot['commerce_product_id'] ?? null)
            || ! is_array($snapshot['references'] ?? null)
            || $snapshot['references'] === []
        ) {
            throw new RuntimeException(
                'Commerce inventory adjustment pinned destination does not match its effect.',
            );
        }

        $references = [];

        foreach ($snapshot['references'] as $stored) {
            if (! is_array($stored)
                || ! is_int($stored['mapping_id'] ?? null)
                || ! is_string($stored['reference_type'] ?? null)
                || ! is_string($stored['external_id'] ?? null)
            ) {
                throw new RuntimeException('Commerce inventory adjustment destination snapshot is malformed.');
            }

            $current = CommerceProductVariantProviderMapping::query()
                ->whereKey($stored['mapping_id'])
                ->lockForUpdate()
                ->first();

            if (! $current instanceof CommerceProductVariantProviderMapping
                || $current->status !== CommerceProductVariantProviderMapping::STATUS_ACTIVE
                || (int) $current->commerce_product_variant_id !== (int) $effect->commerce_product_variant_id
                || $current->provider_key !== $providerKey
                || $current->reference_type !== $stored['reference_type']
                || $current->external_id !== $stored['external_id']
                || $current->external_parent_id !== ($stored['external_parent_id'] ?? null)
                || $current->external_url !== ($stored['external_url'] ?? null)
                || $current->meta !== ($stored['meta'] ?? null)
            ) {
                throw new RuntimeException(
                    'Commerce inventory adjustment mapping changed after destination pinning; reconciliation is required.',
                );
            }

            $references[] = new CommerceProviderReferenceData(
                referenceType: $stored['reference_type'],
                externalId: $stored['external_id'],
                externalParentId: $stored['external_parent_id'] ?? null,
                externalUrl: $stored['external_url'] ?? null,
                meta: $stored['meta'] ?? null,
            );
        }

        return new CommerceProviderVariantReference(
            commerceProductId: $snapshot['commerce_product_id'],
            commerceProductVariantId: (int) $effect->commerce_product_variant_id,
            providerKey: $providerKey,
            references: $references,
            sku: $snapshot['sku'] ?? null,
            barcode: $snapshot['barcode'] ?? null,
        );
    }

    private function adjustmentIdempotencyKey(
        string $providerKey,
        string $effectIdempotencyKey,
    ): string {
        return 'commerce-inventory-adjustment:'
            .hash('sha256', $providerKey.'|'.$effectIdempotencyKey);
    }

    private function nullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
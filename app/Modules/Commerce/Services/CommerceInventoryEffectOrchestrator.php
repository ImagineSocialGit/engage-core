<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Contracts\CommerceInventoryAdjustmentProvider;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentRequest;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentResult;
use App\Modules\Commerce\Data\CommerceInventoryOrchestrationResult;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryAdjustment;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceProductVariant;
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

            $variant = CommerceProductVariant::query()->findOrFail(
                $effect->commerce_product_variant_id,
            );
            $reference = $this->references->resolve($variant, $provider->key());
        } catch (Throwable $exception) {
            $this->markEffectFailed((int) $effect->getKey());

            throw $exception;
        }

        $claim = $this->claimAdjustment(
            effectId: (int) $effect->getKey(),
            providerKey: $provider->key(),
        );

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

        $request = new CommerceInventoryAdjustmentRequest(
            commerceInventoryEffectId: (int) $claimedEffect->getKey(),
            variant: $reference,
            quantityDelta: (string) $claimedEffect->quantity_delta,
            reason: (string) $claimedEffect->reason,
            idempotencyKey: (string) $adjustment->idempotency_key,
            sourceType: (string) $claimedEffect->source_type,
            sourceKey: (string) $claimedEffect->source_key,
            sourceReference: $claimedEffect->source_reference,
            inventoryScope: $claimedEffect->inventory_scope,
            occurredAt: $claimedEffect->occurred_at,
            meta: is_array($claimedEffect->meta) ? $claimedEffect->meta : [],
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
     *     attempt: int
     * }
     */
    private function claimAdjustment(
        int $effectId,
        string $providerKey,
    ): array {
        return DB::transaction(function () use ($effectId, $providerKey): array {
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

            $adjustment = CommerceInventoryAdjustment::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $adjustment instanceof CommerceInventoryAdjustment) {
                $adjustment = CommerceInventoryAdjustment::query()->create([
                    'commerce_inventory_effect_id' => $effect->getKey(),
                    'commerce_product_variant_provider_mapping_id' => null,
                    'provider_key' => $providerKey,
                    'quantity_delta' => $effect->quantity_delta,
                    'status' => CommerceInventoryAdjustment::STATUS_PENDING,
                    'idempotency_key' => $idempotencyKey,
                    'attempts' => 0,
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
                ];
            }

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
                    'meta' => $result->meta !== [] ? $result->meta : null,
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

    private function markEffectFailed(int $effectId): void
    {
        DB::transaction(function () use ($effectId): void {
            $effect = CommerceInventoryEffect::query()
                ->lockForUpdate()
                ->findOrFail($effectId);

            if (in_array($effect->status, [
                CommerceInventoryEffect::STATUS_ADJUSTED,
                CommerceInventoryEffect::STATUS_RECONCILED,
            ], true)) {
                return;
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
        ) {
            throw new RuntimeException(
                'Commerce inventory adjustment idempotency identity was reused for a different effect.',
            );
        }
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
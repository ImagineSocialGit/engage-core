<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Contracts\CommerceInventoryAdjustmentProvider;
use App\Modules\Commerce\Data\CommerceInventoryEffectData;
use App\Modules\Commerce\Data\CommerceInventoryOrchestrationResult;
use App\Modules\Commerce\Data\CommercePointOfSaleLineData;
use App\Modules\Commerce\Data\CommercePointOfSaleSaleData;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Converts independently confirmed POS sales to inventory-authority effects.
 * This does not select a payment processor or reconcile refunds/restocks.
 * Source identity is sale + line, NOT webhook delivery identity.
 */
final class CommercePointOfSaleInventoryEffectService
{
    private const SOURCE_TYPE = 'commerce_pos_sale_line';
    private const REASON = 'pos_sale_consumption';

    public function __construct(
        private readonly CommerceProviderRoleResolver $roles,
        private readonly CommerceInventoryEffectRecorder $recorder,
        private readonly CommerceInventoryEffectOrchestrator $orchestrator,
    ) {}

    /**
     * Persist all merchandise consumption facts atomically without calling a
     * remote inventory authority inside the database transaction. A provider
     * worker may retry these recorded effects independently after a crash.
     *
     * @return array<int, int> Commerce inventory effect IDs
     */
    public function recordPaidSale(CommercePointOfSaleSaleData $sale): array
    {
        if ($sale->saleStatus !== 'completed'
            || $sale->financialStatus !== CommerceOrder::FINANCIAL_STATUS_PAID
            || $sale->paidAt === null
        ) {
            throw new RuntimeException(
                'Commerce POS inventory consumption requires a completed, fully paid sale.',
            );
        }

        $scope = trim($sale->inventoryScope);
        $posProvider = $this->roles->resolve(CommerceProviderRole::PointOfSale, $scope);
        $authority = $this->roles->resolve(CommerceProviderRole::Inventory, $scope);
        $posProviderKey = trim($posProvider->key());
        $authorityKey = trim($authority->key());

        if ($posProviderKey !== trim($sale->providerKey)) {
            throw new RuntimeException(
                'Commerce POS sale provider does not match the selected point-of-sale provider.',
            );
        }

        if ($posProviderKey === $authorityKey) {
            throw new RuntimeException(
                'Commerce POS sale inventory is already managed by its inventory authority; an outbound adjustment is unsafe.',
            );
        }

        if (! $authority instanceof CommerceInventoryAdjustmentProvider) {
            throw new RuntimeException(
                'Commerce inventory authority does not support outbound adjustments.',
            );
        }

        return DB::transaction(function () use ($sale, $scope, $posProviderKey, $authorityKey): array {
            $effectIds = [];

            foreach ($sale->lines as $line) {
                $sourceKey = $this->sourceKey($sale, $line);
                $idempotencyKey = $this->idempotencyKey($sale, $line);
                $existing = CommerceInventoryEffect::query()
                    ->where('source_type', self::SOURCE_TYPE)
                    ->where('source_key', $sourceKey)
                    ->lockForUpdate()
                    ->get();

                if ($existing->count() > 1) {
                    throw new RuntimeException(
                        'Commerce POS sale line has multiple inventory effects; reconciliation is required.',
                    );
                }

                $effect = $existing->first();

                if ($effect instanceof CommerceInventoryEffect) {
                    $this->assertRecordedIdentity(
                        effect: $effect,
                        sale: $sale,
                        line: $line,
                        sourceKey: $sourceKey,
                        idempotencyKey: $idempotencyKey,
                        inventoryAuthorityKey: $authorityKey,
                    );

                    $effectIds[] = (int) $effect->getKey();

                    continue;
                }

                $mapping = CommerceProductVariantProviderMapping::query()
                    ->where('provider_key', $posProviderKey)
                    ->where('reference_type', trim($line->referenceType))
                    ->where('external_id', trim($line->externalVariantId))
                    ->where('status', CommerceProductVariantProviderMapping::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();

                if (! $mapping instanceof CommerceProductVariantProviderMapping) {
                    throw new RuntimeException(
                        'Commerce POS merchandise line has no active explicit canonical variant mapping.',
                    );
                }

                $effect = $this->recorder->record(new CommerceInventoryEffectData(
                    commerceProductVariantId: (int) $mapping->commerce_product_variant_id,
                    quantityDelta: $this->consumptionDelta($line),
                    reason: self::REASON,
                    sourceType: self::SOURCE_TYPE,
                    sourceKey: $sourceKey,
                    idempotencyKey: $idempotencyKey,
                    authorityMode: CommerceInventoryAuthorityMode::AdjustmentRequired,
                    sourceReference: trim($sale->externalSaleId),
                    inventoryScope: $scope,
                    occurredAt: CarbonImmutable::instance($sale->paidAt),
                    meta: [
                        'pos_provider_key' => $posProviderKey,
                        'inventory_provider_key' => $authorityKey,
                        'external_sale_id' => trim($sale->externalSaleId),
                        'external_line_id' => trim($line->externalLineId),
                        'reference_type' => trim($line->referenceType),
                        'external_variant_id' => trim($line->externalVariantId),
                        'external_location_id' => $sale->externalLocationId !== null
                            ? trim($sale->externalLocationId)
                            : null,
                        'provider_event_id' => $sale->providerEventId !== null
                            ? trim($sale->providerEventId)
                            : null,
                        'pos_variant_mapping_id' => (int) $mapping->getKey(),
                    ],
                ));

                $effectIds[] = (int) $effect->getKey();
            }

            return $effectIds;
        });
    }

    /**
     * @param array<int, int> $effectIds
     * @return array<int, CommerceInventoryOrchestrationResult>
     */
    public function applyRecordedEffects(array $effectIds): array
    {
        $results = [];

        foreach (array_values(array_unique($effectIds)) as $id) {
            if (! is_int($id) || $id < 1) {
                throw new RuntimeException('Commerce POS inventory effect identity is invalid.');
            }

            $effect = CommerceInventoryEffect::query()->findOrFail($id);

            if ($effect->source_type !== self::SOURCE_TYPE
                || $effect->reason !== self::REASON
                || $effect->authority_mode !== CommerceInventoryAuthorityMode::AdjustmentRequired
            ) {
                throw new RuntimeException(
                    'Commerce POS service may apply only POS sale-consumption effects.',
                );
            }

            $results[] = $this->orchestrator->orchestrate($effect);
        }

        return $results;
    }

    /**
     * For an asynchronous POS worker. Effects are committed before remote
     * provider calls; a failed or interrupted attempt is safe to retry.
     *
     * @return array<int, CommerceInventoryOrchestrationResult>
     */
    public function consumePaidSale(CommercePointOfSaleSaleData $sale): array
    {
        return $this->applyRecordedEffects($this->recordPaidSale($sale));
    }

    private function assertRecordedIdentity(
        CommerceInventoryEffect $effect,
        CommercePointOfSaleSaleData $sale,
        CommercePointOfSaleLineData $line,
        string $sourceKey,
        string $idempotencyKey,
        string $inventoryAuthorityKey,
    ): void {
        $meta = is_array($effect->meta) ? $effect->meta : [];
        $expected = [
            'pos_provider_key' => trim($sale->providerKey),
            'inventory_provider_key' => $inventoryAuthorityKey,
            'external_sale_id' => trim($sale->externalSaleId),
            'external_line_id' => trim($line->externalLineId),
            'reference_type' => trim($line->referenceType),
            'external_variant_id' => trim($line->externalVariantId),
            'external_location_id' => $sale->externalLocationId !== null
                ? trim($sale->externalLocationId)
                : null,
        ];

        if ($effect->idempotency_key !== $idempotencyKey
            || $effect->source_key !== $sourceKey
            || $effect->source_reference !== trim($sale->externalSaleId)
            || $effect->reason !== self::REASON
            || $effect->authority_mode !== CommerceInventoryAuthorityMode::AdjustmentRequired
            || $effect->inventory_scope !== trim($sale->inventoryScope)
            || (string) $effect->quantity_delta !== $this->consumptionDelta($line)
        ) {
            throw new RuntimeException(
                'Commerce POS sale line changed after inventory consumption was recorded; reconciliation is required.',
            );
        }

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $meta) || $meta[$key] !== $value) {
                throw new RuntimeException(
                    'Commerce POS sale line identity changed after recording; reconciliation is required.',
                );
            }
        }
    }

    private function consumptionDelta(CommercePointOfSaleLineData $line): string
    {
        return '-'.$line->quantity.'.0000';
    }

    private function sourceKey(
        CommercePointOfSaleSaleData $sale,
        CommercePointOfSaleLineData $line,
    ): string {
        return 'pos-line:'.$this->identityHash($sale, $line);
    }

    private function idempotencyKey(
        CommercePointOfSaleSaleData $sale,
        CommercePointOfSaleLineData $line,
    ): string {
        return 'commerce-pos-consumption:'.$this->identityHash($sale, $line);
    }

    private function identityHash(
        CommercePointOfSaleSaleData $sale,
        CommercePointOfSaleLineData $line,
    ): string {
        return hash('sha256', json_encode([
            trim($sale->providerKey),
            trim($sale->externalSaleId),
            trim($line->externalLineId),
        ], JSON_THROW_ON_ERROR));
    }
}
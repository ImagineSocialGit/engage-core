<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Contracts\CommerceInventoryAdjustmentProvider;
use App\Modules\Commerce\Contracts\CommercePointOfSaleProvider;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentRequest;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentResult;
use App\Modules\Commerce\Data\CommerceInventoryEffectData;
use App\Modules\Commerce\Data\CommerceInventoryOrchestrationResult;
use App\Modules\Commerce\Data\CommercePointOfSaleLineData;
use App\Modules\Commerce\Data\CommercePointOfSaleSaleData;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryAdjustment;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Modules\Commerce\Services\CommerceInventoryEffectOrchestrator;
use App\Modules\Commerce\Services\CommerceInventoryEffectRecorder;
use App\Modules\Commerce\Services\CommercePointOfSaleInventoryEffectService;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use App\Modules\Commerce\Services\CommerceProviderVariantReferenceResolver;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class CommercePointOfSaleInventoryEffectTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_pos_sale_decrements_inventory_authority_once_across_replayed_events(): void
    {
        [$variant, $posMapping] = $this->mappedVariant();
        $inventory = new PosInventoryAuthorityFixture();
        $service = $this->service($inventory);

        $first = $service->consumePaidSale($this->sale(
            line: $this->line(quantity: 2),
            providerEventId: 'delivery-1',
        ));
        $second = $service->consumePaidSale($this->sale(
            line: $this->line(quantity: 2),
            providerEventId: 'delivery-2',
        ));

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame(CommerceInventoryOrchestrationResult::OUTCOME_ADJUSTED, $first[0]->outcome);
        $this->assertTrue($first[0]->providerMutationAttempted);
        $this->assertFalse($second[0]->providerMutationAttempted);
        $this->assertCount(1, $inventory->requests);
        $this->assertSame('-2.0000', $inventory->requests[0]->quantityDelta);
        $this->assertSame('shopify', $inventory->requests[0]->variant->providerKey);
        $this->assertSame('shopify-variation-1', $inventory->requests[0]->variant->reference('catalog_variant')?->externalId);
        $this->assertSame('show', $inventory->requests[0]->inventoryScope);

        $effect = CommerceInventoryEffect::query()->sole();
        $adjustment = CommerceInventoryAdjustment::query()->sole();

        $this->assertSame((int) $variant->getKey(), $effect->commerce_product_variant_id);
        $this->assertSame('square', $effect->meta['pos_provider_key']);
        $this->assertSame('shopify', $effect->meta['inventory_provider_key']);
        $this->assertSame((int) $posMapping->getKey(), $effect->meta['pos_variant_mapping_id']);
        $this->assertSame('square-location-1', $effect->meta['external_location_id']);
        $this->assertSame(CommerceInventoryAuthorityMode::AdjustmentRequired, $effect->authority_mode);
        $this->assertSame(CommerceInventoryEffect::STATUS_ADJUSTED, $effect->status);
        $this->assertSame(CommerceInventoryAdjustment::STATUS_SUCCEEDED, $adjustment->status);
    }

    public function test_unpaid_unauthorized_and_noncompleted_sales_do_not_record_or_mutate_stock(): void
    {
        $this->mappedVariant();
        $inventory = new PosInventoryAuthorityFixture();
        $service = $this->service($inventory);

        foreach ([
            ['completed', CommerceOrder::FINANCIAL_STATUS_PENDING, new DateTimeImmutable('2026-11-18T12:00:00Z')],
            ['completed', CommerceOrder::FINANCIAL_STATUS_AUTHORIZED, new DateTimeImmutable('2026-11-18T12:00:00Z')],
            ['completed', CommerceOrder::FINANCIAL_STATUS_VOIDED, new DateTimeImmutable('2026-11-18T12:00:00Z')],
            ['completed', CommerceOrder::FINANCIAL_STATUS_PAID, null],
            ['cancelled', CommerceOrder::FINANCIAL_STATUS_PAID, new DateTimeImmutable('2026-11-18T12:00:00Z')],
            ['completed', CommerceOrder::FINANCIAL_STATUS_REFUNDED, new DateTimeImmutable('2026-11-18T12:00:00Z')],
        ] as [$status, $financial, $paidAt]) {
            try {
                $service->consumePaidSale($this->sale(
                    line: $this->line(),
                    saleStatus: $status,
                    financialStatus: $financial,
                    paidAt: $paidAt,
                ));
                $this->fail('Unsafe POS sale was accepted for inventory consumption.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('completed, fully paid', $exception->getMessage());
            }
        }

        $this->assertSame(0, CommerceInventoryEffect::query()->count());
        $this->assertSame(0, CommerceInventoryAdjustment::query()->count());
        $this->assertCount(0, $inventory->requests);
    }

    public function test_unmapped_merchandise_rolls_back_every_sale_line_before_inventory_mutation(): void
    {
        $this->mappedVariant();
        $inventory = new PosInventoryAuthorityFixture();
        $service = $this->service($inventory);

        try {
            $service->consumePaidSale($this->sale(lines: [
                $this->line(),
                $this->line(externalLineId: 'line-missing', externalVariantId: 'not-mapped'),
            ]));
            $this->fail('Unmapped merchandise was consumed without review.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('explicit canonical variant mapping', $exception->getMessage());
        }

        $this->assertSame(0, CommerceInventoryEffect::query()->count());
        $this->assertSame(0, CommerceInventoryAdjustment::query()->count());
        $this->assertCount(0, $inventory->requests);
    }

    public function test_explicit_reference_type_is_required_and_sku_cannot_resolve_identity(): void
    {
        $this->mappedVariant();
        $service = $this->service(new PosInventoryAuthorityFixture());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('explicit canonical variant mapping');

        $service->recordPaidSale($this->sale(line: $this->line(referenceType: 'sku')));
    }

    public function test_changed_quantity_or_variant_for_same_sale_line_is_a_conflict_not_an_extra_adjustment(): void
    {
        $this->mappedVariant();
        $inventory = new PosInventoryAuthorityFixture();
        $service = $this->service($inventory);
        $service->consumePaidSale($this->sale(line: $this->line()));

        foreach ([
            $this->line(quantity: 2),
            $this->line(externalVariantId: 'other-variation'),
        ] as $changedLine) {
            try {
                $service->consumePaidSale($this->sale(line: $changedLine));
                $this->fail('An existing POS line identity was reused for different merchandise.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('reconciliation is required', $exception->getMessage());
            }
        }

        $this->assertSame(1, CommerceInventoryEffect::query()->count());
        $this->assertSame(1, CommerceInventoryAdjustment::query()->count());
        $this->assertCount(1, $inventory->requests);
    }

    public function test_replaying_success_does_not_require_current_pos_mapping_to_remain_active(): void
    {
        [, $mapping] = $this->mappedVariant();
        $inventory = new PosInventoryAuthorityFixture();
        $service = $this->service($inventory);
        $service->consumePaidSale($this->sale(line: $this->line()));

        $mapping->update(['status' => CommerceProductVariantProviderMapping::STATUS_INACTIVE]);

        $replay = $service->consumePaidSale($this->sale(
            line: $this->line(),
            providerEventId: 'different-delivery',
        ));

        $this->assertCount(1, $replay);
        $this->assertFalse($replay[0]->providerMutationAttempted);
        $this->assertCount(1, $inventory->requests);
    }

    public function test_location_scope_change_for_an_existing_sale_line_is_not_silent(): void
    {
        $this->mappedVariant();
        $inventory = new PosInventoryAuthorityFixture();
        $service = $this->service($inventory);
        $service->consumePaidSale($this->sale(line: $this->line()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('reconciliation is required');

        $service->consumePaidSale($this->sale(
            line: $this->line(),
            inventoryScope: 'another-warehouse',
        ));
    }

    public function test_provider_failure_persists_recoverable_effect_and_reuses_the_same_adjustment_identity(): void
    {
        $this->mappedVariant();
        $inventory = new PosInventoryAuthorityFixture();
        $inventory->failNext = true;
        $service = $this->service($inventory);

        try {
            $service->consumePaidSale($this->sale(line: $this->line()));
            $this->fail('The injected provider failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated inventory provider outage.', $exception->getMessage());
        }

        $effect = CommerceInventoryEffect::query()->sole();
        $adjustment = CommerceInventoryAdjustment::query()->sole();

        $this->assertSame(CommerceInventoryEffect::STATUS_FAILED, $effect->status);
        $this->assertSame(CommerceInventoryAdjustment::STATUS_FAILED, $adjustment->status);
        $this->assertSame(1, $adjustment->attempts);

        $service->consumePaidSale($this->sale(
            line: $this->line(),
            providerEventId: 'recovery-delivery',
        ));

        $this->assertCount(2, $inventory->requests);
        $this->assertSame(
            $inventory->requests[0]->idempotencyKey,
            $inventory->requests[1]->idempotencyKey,
        );
        $this->assertSame(1, CommerceInventoryEffect::query()->count());
        $this->assertSame(2, (int) $adjustment->fresh()->attempts);
        $this->assertSame(CommerceInventoryEffect::STATUS_ADJUSTED, $effect->fresh()->status);
    }

    public function test_inventory_effects_can_be_recorded_then_applied_outside_the_transaction(): void
    {
        $this->mappedVariant();
        $inventory = new PosInventoryAuthorityFixture();
        $service = $this->service($inventory);

        $ids = $service->recordPaidSale($this->sale(line: $this->line()));

        $this->assertCount(1, $ids);
        $this->assertCount(0, $inventory->requests);
        $this->assertSame(CommerceInventoryEffect::STATUS_RECORDED, CommerceInventoryEffect::query()->sole()->status);

        $service->applyRecordedEffects($ids);
        $service->applyRecordedEffects($ids);

        $this->assertCount(1, $inventory->requests);
        $this->assertSame(CommerceInventoryEffect::STATUS_ADJUSTED, CommerceInventoryEffect::query()->sole()->status);
    }

    public function test_pos_service_will_not_apply_an_authority_already_applied_effect(): void
    {
        $variant = CommerceProductVariant::factory()->create();
        $existing = app(CommerceInventoryEffectRecorder::class)->record(new CommerceInventoryEffectData(
            commerceProductVariantId: (int) $variant->getKey(),
            quantityDelta: '-1.0000',
            reason: 'authoritative_order_consumption',
            sourceType: 'commerce_order_item',
            sourceKey: 'shopify-order-line',
            idempotencyKey: 'shopify-processed-line',
            authorityMode: CommerceInventoryAuthorityMode::AuthorityAlreadyApplied,
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('only POS sale-consumption effects');

        $this->service(new PosInventoryAuthorityFixture())->applyRecordedEffects([(int) $existing->getKey()]);
    }

    public function test_wrong_pos_provider_binding_fails_without_effects(): void
    {
        $this->mappedVariant();
        $inventory = new PosInventoryAuthorityFixture();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match the selected point-of-sale provider');

        try {
            $this->service($inventory)->recordPaidSale($this->sale(
                line: $this->line(),
                providerKey: 'unselected-pos',
            ));
        } finally {
            $this->assertSame(0, CommerceInventoryEffect::query()->count());
            $this->assertCount(0, $inventory->requests);
        }
    }

    public function test_invalid_duplicate_sale_line_identity_is_rejected_before_persistence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unique within a sale');

        $this->sale(lines: [$this->line(), $this->line()]);
    }

    /** @return array{0: CommerceProductVariant, 1: CommerceProductVariantProviderMapping} */
    private function mappedVariant(): array
    {
        $variant = CommerceProductVariant::factory()->active()->create();
        $posMapping = CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'provider_key' => 'square',
            'reference_type' => 'catalog_variation',
            'external_id' => 'square-variation-1',
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
        ]);

        CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'provider_key' => 'shopify',
            'reference_type' => 'catalog_variant',
            'external_id' => 'shopify-variation-1',
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
        ]);

        return [$variant, $posMapping];
    }

    private function line(
        int $quantity = 1,
        string $externalLineId = 'line-1',
        string $externalVariantId = 'square-variation-1',
        string $referenceType = 'catalog_variation',
    ): CommercePointOfSaleLineData {
        return new CommercePointOfSaleLineData(
            externalLineId: $externalLineId,
            referenceType: $referenceType,
            externalVariantId: $externalVariantId,
            quantity: $quantity,
        );
    }

    /** @param array<int, CommercePointOfSaleLineData>|null $lines */
    private function sale(
        ?CommercePointOfSaleLineData $line = null,
        ?array $lines = null,
        string $providerKey = 'square',
        string $saleStatus = 'completed',
        string $financialStatus = CommerceOrder::FINANCIAL_STATUS_PAID,
        ?DateTimeImmutable $paidAt = new DateTimeImmutable('2026-11-18T12:00:00Z'),
        string $inventoryScope = 'show',
        ?string $providerEventId = 'delivery-1',
    ): CommercePointOfSaleSaleData {
        return new CommercePointOfSaleSaleData(
            providerKey: $providerKey,
            externalSaleId: 'sale-100',
            saleStatus: $saleStatus,
            financialStatus: $financialStatus,
            paidAt: $paidAt,
            inventoryScope: $inventoryScope,
            lines: $lines ?? [$line ?? $this->line()],
            externalLocationId: 'square-location-1',
            providerEventId: $providerEventId,
        );
    }

    private function service(PosInventoryAuthorityFixture $inventory): CommercePointOfSaleInventoryEffectService
    {
        $roles = new CommerceProviderRoleResolver(
            providers: new CommerceProviderRegistry([
                new PosSaleFixtureProvider(),
                $inventory,
            ]),
            config: new Repository([
                'commerce' => [
                    'provider_roles' => [
                        CommerceProviderRole::PointOfSale->value => [
                            'default' => 'square',
                            'scopes' => [],
                        ],
                        CommerceProviderRole::Inventory->value => [
                            'default' => 'shopify',
                            'scopes' => [],
                        ],
                        // Orders stays separately configured; POS does not use it.
                        CommerceProviderRole::Orders->value => [
                            'default' => 'shopify',
                            'scopes' => [],
                        ],
                    ],
                ],
            ]),
        );

        return new CommercePointOfSaleInventoryEffectService(
            roles: $roles,
            recorder: app(CommerceInventoryEffectRecorder::class),
            orchestrator: new CommerceInventoryEffectOrchestrator(
                roles: $roles,
                references: new CommerceProviderVariantReferenceResolver(),
            ),
        );
    }
}

final class PosSaleFixtureProvider implements CommercePointOfSaleProvider
{
    public function key(): string
    {
        return 'square';
    }
}

final class PosInventoryAuthorityFixture implements CommerceInventoryAdjustmentProvider
{
    /** @var array<int, CommerceInventoryAdjustmentRequest> */
    public array $requests = [];
    public bool $failNext = false;

    public function key(): string
    {
        return 'shopify';
    }

    public function adjustInventory(CommerceInventoryAdjustmentRequest $request): CommerceInventoryAdjustmentResult
    {
        $this->requests[] = $request;

        if ($this->failNext) {
            $this->failNext = false;

            throw new RuntimeException('Simulated inventory provider outage.');
        }

        return new CommerceInventoryAdjustmentResult(
            providerKey: $this->key(),
            idempotencyKey: $request->idempotencyKey,
            externalId: 'shopify-adjustment-1',
        );
    }
}
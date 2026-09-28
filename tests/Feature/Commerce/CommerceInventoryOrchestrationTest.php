<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Contracts\CommerceInventoryAdjustmentProvider;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentRequest;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentResult;
use App\Modules\Commerce\Data\CommerceInventoryEffectData;
use App\Modules\Commerce\Data\CommerceInventoryOrchestrationResult;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryAdjustment;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Modules\Commerce\Services\CommerceInventoryEffectOrchestrator;
use App\Modules\Commerce\Services\CommerceInventoryEffectRecorder;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use App\Modules\Commerce\Services\CommerceProviderVariantReferenceResolver;
use DateTimeImmutable;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CommerceInventoryOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authority_already_applied_reconciles_without_outbound_adjustment(): void
    {
        $variant = CommerceProductVariant::factory()->create();
        $effect = $this->recordEffect(
            variant: $variant,
            authorityMode: CommerceInventoryAuthorityMode::AuthorityAlreadyApplied,
            idempotencyKey: 'shopify:order-100:line-1',
        );

        $result = $this->orchestrator()->orchestrate($effect);

        $this->assertSame(
            CommerceInventoryOrchestrationResult::OUTCOME_RECONCILED,
            $result->outcome,
        );
        $this->assertFalse($result->providerMutationAttempted);
        $this->assertSame(
            CommerceInventoryEffect::STATUS_RECONCILED,
            $effect->fresh()->status,
        );
        $this->assertSame(0, CommerceInventoryAdjustment::query()->count());
    }

    public function test_authority_operation_pending_waits_without_outbound_adjustment(): void
    {
        $variant = CommerceProductVariant::factory()->create();
        $effect = $this->recordEffect(
            variant: $variant,
            authorityMode: CommerceInventoryAuthorityMode::AuthorityOperationPending,
            idempotencyKey: 'checkout:pending-100:variant-1',
        );

        $result = $this->orchestrator()->orchestrate($effect);

        $this->assertSame(
            CommerceInventoryOrchestrationResult::OUTCOME_AWAITING_AUTHORITY_OPERATION,
            $result->outcome,
        );
        $this->assertFalse($result->providerMutationAttempted);
        $this->assertSame(
            CommerceInventoryEffect::STATUS_RECORDED,
            $effect->fresh()->status,
        );
        $this->assertSame(0, CommerceInventoryAdjustment::query()->count());
    }

    public function test_pending_authority_operation_can_be_confirmed_without_outbound_adjustment(): void
    {
        $variant = CommerceProductVariant::factory()->create();
        $effect = $this->recordEffect(
            variant: $variant,
            authorityMode: CommerceInventoryAuthorityMode::AuthorityOperationPending,
            idempotencyKey: 'checkout:pending-200:variant-1',
        );
        $orchestrator = $this->orchestrator();

        $waiting = $orchestrator->orchestrate($effect);
        $confirmed = $orchestrator->confirmAuthorityOperationApplied($effect);

        $this->assertSame(
            CommerceInventoryOrchestrationResult::OUTCOME_AWAITING_AUTHORITY_OPERATION,
            $waiting->outcome,
        );
        $this->assertSame(
            CommerceInventoryOrchestrationResult::OUTCOME_RECONCILED,
            $confirmed->outcome,
        );
        $this->assertFalse($confirmed->providerMutationAttempted);
        $this->assertSame(
            CommerceInventoryEffect::STATUS_RECONCILED,
            $effect->fresh()->status,
        );
        $this->assertSame(0, CommerceInventoryAdjustment::query()->count());
    }

    public function test_adjustment_required_executes_one_provider_mutation_and_is_idempotent_after_success(): void
    {
        [$variant, $mapping] = $this->mappedVariant();
        $effect = $this->recordEffect(
            variant: $variant,
            authorityMode: CommerceInventoryAuthorityMode::AdjustmentRequired,
            idempotencyKey: 'square:sale-100:variation-1',
        );
        $provider = new InventoryAdjustmentFixtureProvider('inventory-provider');
        $orchestrator = $this->orchestrator($provider);

        $first = $orchestrator->orchestrate($effect);
        $second = $orchestrator->orchestrate($effect);

        $this->assertSame(
            CommerceInventoryOrchestrationResult::OUTCOME_ADJUSTED,
            $first->outcome,
        );
        $this->assertTrue($first->providerMutationAttempted);
        $this->assertSame(
            CommerceInventoryOrchestrationResult::OUTCOME_ADJUSTED,
            $second->outcome,
        );
        $this->assertFalse($second->providerMutationAttempted);
        $this->assertCount(1, $provider->requests);

        $request = $provider->requests[0];

        $this->assertSame((int) $effect->getKey(), $request->commerceInventoryEffectId);
        $this->assertSame('-1.0000', $request->quantityDelta);
        $this->assertSame('inventory-provider', $request->variant->providerKey);
        $this->assertSame(
            'variant-100',
            $request->variant->reference('catalog_variant')?->externalId,
        );

        $adjustment = CommerceInventoryAdjustment::query()->firstOrFail();

        $this->assertSame(CommerceInventoryAdjustment::STATUS_SUCCEEDED, $adjustment->status);
        $this->assertSame(1, $adjustment->attempts);
        $this->assertNotNull($adjustment->requested_at);
        $this->assertNull($adjustment->claimed_at);
        $this->assertNotNull($adjustment->completed_at);
        $this->assertSame('fixture-adjustment', $adjustment->external_id);
        $this->assertSame(
            CommerceInventoryEffect::STATUS_ADJUSTED,
            $effect->fresh()->status,
        );
        $this->assertNull($adjustment->commerce_product_variant_provider_mapping_id);
        $this->assertSame((int) $mapping->commerce_product_variant_id, (int) $variant->getKey());
    }

    public function test_failed_adjustment_can_retry_with_the_same_provider_idempotency_key(): void
    {
        [$variant] = $this->mappedVariant();
        $effect = $this->recordEffect(
            variant: $variant,
            authorityMode: CommerceInventoryAuthorityMode::AdjustmentRequired,
            idempotencyKey: 'square:sale-200:variation-1',
        );
        $provider = new InventoryAdjustmentFixtureProvider('inventory-provider');
        $provider->fail = true;
        $orchestrator = $this->orchestrator($provider);

        try {
            $orchestrator->orchestrate($effect);
            $this->fail('Expected the fixture inventory adjustment to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture inventory adjustment failure.', $exception->getMessage());
        }

        $failed = CommerceInventoryAdjustment::query()->firstOrFail();
        $firstIdempotencyKey = $provider->requests[0]->idempotencyKey;

        $this->assertSame(CommerceInventoryAdjustment::STATUS_FAILED, $failed->status);
        $this->assertSame(1, $failed->attempts);
        $this->assertNull($failed->claimed_at);
        $this->assertSame(
            CommerceInventoryEffect::STATUS_FAILED,
            $effect->fresh()->status,
        );

        $provider->fail = false;
        $result = $orchestrator->orchestrate($effect);

        $this->assertSame(
            CommerceInventoryOrchestrationResult::OUTCOME_ADJUSTED,
            $result->outcome,
        );
        $this->assertCount(2, $provider->requests);
        $this->assertSame($firstIdempotencyKey, $provider->requests[1]->idempotencyKey);

        $succeeded = $failed->fresh();

        $this->assertSame(CommerceInventoryAdjustment::STATUS_SUCCEEDED, $succeeded->status);
        $this->assertSame(2, $succeeded->attempts);
        $this->assertNull($succeeded->failure_reason);
        $this->assertSame(
            CommerceInventoryEffect::STATUS_ADJUSTED,
            $effect->fresh()->status,
        );
    }

    public function test_recent_processing_claim_does_not_fan_out_a_second_provider_call(): void
    {
        [$variant] = $this->mappedVariant();
        $effect = $this->recordEffect(
            variant: $variant,
            authorityMode: CommerceInventoryAuthorityMode::AdjustmentRequired,
            idempotencyKey: 'square:sale-300:variation-1',
        );
        $provider = new InventoryAdjustmentFixtureProvider('inventory-provider');
        $idempotencyKey = $this->adjustmentIdempotencyKey(
            providerKey: $provider->key(),
            effectIdempotencyKey: (string) $effect->idempotency_key,
        );

        $adjustment = CommerceInventoryAdjustment::query()->create([
            'commerce_inventory_effect_id' => $effect->getKey(),
            'provider_key' => $provider->key(),
            'quantity_delta' => $effect->quantity_delta,
            'status' => CommerceInventoryAdjustment::STATUS_PROCESSING,
            'idempotency_key' => $idempotencyKey,
            'attempts' => 1,
            'requested_at' => now(),
            'claimed_at' => now(),
        ]);

        $result = $this->orchestrator($provider)->orchestrate($effect);

        $this->assertSame(
            CommerceInventoryOrchestrationResult::OUTCOME_ADJUSTMENT_IN_PROGRESS,
            $result->outcome,
        );
        $this->assertFalse($result->providerMutationAttempted);
        $this->assertSame((int) $adjustment->getKey(), $result->commerceInventoryAdjustmentId);
        $this->assertCount(0, $provider->requests);
    }

    public function test_stale_processing_claim_is_reclaimed_using_the_same_adjustment_identity(): void
    {
        [$variant] = $this->mappedVariant();
        $effect = $this->recordEffect(
            variant: $variant,
            authorityMode: CommerceInventoryAuthorityMode::AdjustmentRequired,
            idempotencyKey: 'square:sale-400:variation-1',
        );
        $provider = new InventoryAdjustmentFixtureProvider('inventory-provider');
        $idempotencyKey = $this->adjustmentIdempotencyKey(
            providerKey: $provider->key(),
            effectIdempotencyKey: (string) $effect->idempotency_key,
        );

        $adjustment = CommerceInventoryAdjustment::query()->create([
            'commerce_inventory_effect_id' => $effect->getKey(),
            'provider_key' => $provider->key(),
            'quantity_delta' => $effect->quantity_delta,
            'status' => CommerceInventoryAdjustment::STATUS_PROCESSING,
            'idempotency_key' => $idempotencyKey,
            'attempts' => 1,
            'requested_at' => now()->subMinutes(10),
            'claimed_at' => now()->subMinutes(10),
        ]);

        $result = $this->orchestrator($provider)->orchestrate($effect);

        $this->assertSame(
            CommerceInventoryOrchestrationResult::OUTCOME_ADJUSTED,
            $result->outcome,
        );
        $this->assertTrue($result->providerMutationAttempted);
        $this->assertCount(1, $provider->requests);
        $this->assertSame($idempotencyKey, $provider->requests[0]->idempotencyKey);
        $this->assertSame(2, $adjustment->fresh()->attempts);
    }

    public function test_missing_provider_mapping_marks_adjustment_required_effect_failed_without_mutation(): void
    {
        $variant = CommerceProductVariant::factory()->create();
        $effect = $this->recordEffect(
            variant: $variant,
            authorityMode: CommerceInventoryAuthorityMode::AdjustmentRequired,
            idempotencyKey: 'square:sale-500:variation-1',
        );
        $provider = new InventoryAdjustmentFixtureProvider('inventory-provider');

        try {
            $this->orchestrator($provider)->orchestrate($effect);
            $this->fail('Expected missing provider mapping to block inventory adjustment.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Commerce variant has no active explicit mapping for the requested provider.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(
            CommerceInventoryEffect::STATUS_FAILED,
            $effect->fresh()->status,
        );
        $this->assertSame(0, CommerceInventoryAdjustment::query()->count());
        $this->assertCount(0, $provider->requests);
    }

    /**
     * @return array{0: CommerceProductVariant, 1: CommerceProductVariantProviderMapping}
     */
    private function mappedVariant(): array
    {
        $product = CommerceProduct::factory()->active()->create();
        $variant = CommerceProductVariant::factory()
            ->for($product, 'commerceProduct')
            ->active()
            ->create();
        $mapping = CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'provider_key' => 'inventory-provider',
            'reference_type' => 'catalog_variant',
            'external_id' => 'variant-100',
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
        ]);

        return [$variant, $mapping];
    }

    private function recordEffect(
        CommerceProductVariant $variant,
        CommerceInventoryAuthorityMode $authorityMode,
        string $idempotencyKey,
    ): CommerceInventoryEffect {
        return app(CommerceInventoryEffectRecorder::class)->record(
            new CommerceInventoryEffectData(
                commerceProductVariantId: (int) $variant->getKey(),
                quantityDelta: '-1',
                reason: 'completed_sale',
                sourceType: 'provider',
                sourceKey: 'sale-provider',
                idempotencyKey: $idempotencyKey,
                authorityMode: $authorityMode,
                sourceReference: 'sale-100',
                inventoryScope: 'default',
                occurredAt: now(),
                meta: [
                    'fixture' => true,
                ],
            ),
        );
    }

    private function orchestrator(
        ?InventoryAdjustmentFixtureProvider $provider = null,
    ): CommerceInventoryEffectOrchestrator {
        $providers = $provider !== null ? [$provider] : [];
        $roles = new CommerceProviderRoleResolver(
            providers: new CommerceProviderRegistry($providers),
            config: new Repository([
                'commerce' => [
                    'provider_roles' => [
                        CommerceProviderRole::Inventory->value => [
                            'default' => $provider?->key(),
                            'scopes' => [],
                        ],
                    ],
                ],
            ]),
        );

        return new CommerceInventoryEffectOrchestrator(
            roles: $roles,
            references: new CommerceProviderVariantReferenceResolver(),
        );
    }

    private function adjustmentIdempotencyKey(
        string $providerKey,
        string $effectIdempotencyKey,
    ): string {
        return 'commerce-inventory-adjustment:'
            .hash('sha256', $providerKey.'|'.$effectIdempotencyKey);
    }
}

final class InventoryAdjustmentFixtureProvider implements CommerceInventoryAdjustmentProvider
{
    /** @var array<int, CommerceInventoryAdjustmentRequest> */
    public array $requests = [];

    public bool $fail = false;

    public function __construct(
        private readonly string $providerKey,
    ) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function adjustInventory(
        CommerceInventoryAdjustmentRequest $request,
    ): CommerceInventoryAdjustmentResult {
        $this->requests[] = $request;

        if ($this->fail) {
            throw new RuntimeException('Fixture inventory adjustment failure.');
        }

        return new CommerceInventoryAdjustmentResult(
            providerKey: $this->providerKey,
            idempotencyKey: $request->idempotencyKey,
            externalId: 'fixture-adjustment',
            completedAt: new DateTimeImmutable('2026-09-28T18:00:00Z'),
            meta: [
                'fixture' => true,
            ],
        );
    }
}
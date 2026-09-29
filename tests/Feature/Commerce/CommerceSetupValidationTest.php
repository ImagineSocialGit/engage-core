<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Contracts\CommerceInventoryAdjustmentProvider;
use App\Modules\Commerce\Contracts\CommerceInventoryProvider;
use App\Modules\Commerce\Contracts\CommerceInventoryReadProvider;
use App\Modules\Commerce\Contracts\CommerceOrderProvider;
use App\Modules\Commerce\Contracts\CommerceProvider;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentRequest;
use App\Modules\Commerce\Data\CommerceInventoryAdjustmentResult;
use App\Modules\Commerce\Data\CommerceInventoryEffectData;
use App\Modules\Commerce\Data\CommerceInventoryState;
use App\Modules\Commerce\Data\CommerceOrderSnapshotData;
use App\Modules\Commerce\Data\CommerceProviderVariantReference;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Modules\Commerce\Services\CommerceInventoryEffectRecorder;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Validation\CommerceSetupValidationContributor;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class CommerceSetupValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_provider_roles_and_executable_inventory_authority_have_no_findings(): void
    {
        $provider = new CompleteCommerceAuthorityProvider('provider-a');
        $findings = $this->findings(
            providers: [$provider],
            bindings: [
                CommerceProviderRole::Orders->value => [
                    'default' => 'provider-a',
                    'scopes' => [],
                ],
                CommerceProviderRole::Inventory->value => [
                    'default' => 'provider-a',
                    'scopes' => [],
                ],
            ],
        );

        $this->assertSame([], $findings);
    }

    public function test_optional_roles_accept_null_or_blank_default_as_unconfigured(): void
    {
        $findings = $this->findings(
            providers: [],
            bindings: [
                CommerceProviderRole::Promotion->value => [
                    'default' => null,
                    'scopes' => [],
                ],
                CommerceProviderRole::Payment->value => [
                    'default' => '',
                    'scopes' => [],
                ],
                CommerceProviderRole::Fulfillment->value => [
                    'default' => '   ',
                    'scopes' => [],
                ],
                CommerceProviderRole::PointOfSale->value => [
                    'default' => null,
                    'scopes' => [],
                ],
            ],
        );

        $this->assertSame([], $findings);
    }

    public function test_non_string_non_null_default_provider_remains_invalid(): void
    {
        $findings = $this->findings(
            providers: [],
            bindings: [
                CommerceProviderRole::Promotion->value => [
                    'default' => 123,
                    'scopes' => [],
                ],
            ],
        );

        $this->assertSame(
            ['commerce.provider_roles.provider_key_invalid'],
            array_column($findings, 'code'),
        );
    }

    public function test_invalid_role_provider_and_inventory_capabilities_are_reported(): void
    {
        $findings = $this->findings(
            providers: [new BasicCommerceProvider('basic')],
            bindings: [
                'unknown_role' => ['default' => 'basic'],
                CommerceProviderRole::Orders->value => [
                    'default' => 'missing',
                    'scopes' => [],
                ],
                CommerceProviderRole::Inventory->value => [
                    'default' => 'basic',
                    'scopes' => [],
                ],
            ],
        );

        $this->assertEqualsCanonicalizing(
            [
                'commerce.provider_roles.unknown_role',
                'commerce.provider_roles.provider_unregistered',
                'commerce.provider_roles.contract_mismatch',
            ],
            array_column($findings, 'code'),
        );
    }

    public function test_inventory_role_requires_both_read_and_adjustment_capabilities(): void
    {
        $provider = new PartialInventoryProvider('inventory-a');
        $findings = $this->findings(
            providers: [$provider],
            bindings: [
                CommerceProviderRole::Inventory->value => [
                    'default' => 'inventory-a',
                    'scopes' => [],
                ],
            ],
        );

        $this->assertEqualsCanonicalizing(
            ['commerce.inventory.adjustment_capability_missing'],
            array_column($findings, 'code'),
        );
    }

    public function test_unresolved_adjustment_effect_requires_an_active_inventory_mapping(): void
    {
        $product = CommerceProduct::factory()->active()->create();
        $variant = CommerceProductVariant::factory()
            ->for($product, 'commerceProduct')
            ->active()
            ->create();
        $provider = new CompleteCommerceAuthorityProvider('inventory-a');

        app(CommerceInventoryEffectRecorder::class)->record(
            new CommerceInventoryEffectData(
                commerceProductVariantId: (int) $variant->getKey(),
                quantityDelta: '-1',
                reason: 'completed_sale',
                sourceType: 'provider',
                sourceKey: 'sale-provider',
                idempotencyKey: 'setup-validation-effect',
                authorityMode: CommerceInventoryAuthorityMode::AdjustmentRequired,
                sourceReference: 'sale-1',
            ),
        );

        $bindings = [
            CommerceProviderRole::Inventory->value => [
                'default' => 'inventory-a',
                'scopes' => [],
            ],
        ];

        $missing = $this->findings([$provider], $bindings);

        $this->assertContains(
            'commerce.inventory.effect_mapping_missing',
            array_column($missing, 'code'),
        );

        CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'provider_key' => 'inventory-a',
            'reference_type' => 'product_variant',
            'external_id' => 'variant-1',
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
        ]);

        $resolved = $this->findings([$provider], $bindings);

        $this->assertNotContains(
            'commerce.inventory.effect_mapping_missing',
            array_column($resolved, 'code'),
        );
    }

    /**
     * @param array<int, CommerceProvider> $providers
     * @param array<string, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    private function findings(array $providers, array $bindings): array
    {
        $contributor = new CommerceSetupValidationContributor(
            providers: new CommerceProviderRegistry($providers),
            config: new Repository([
                'commerce' => [
                    'provider_roles' => $bindings,
                ],
            ]),
        );

        return array_map(
            static fn ($finding): array => $finding->toArray(),
            iterator_to_array($contributor->findings(), false),
        );
    }
}

final readonly class BasicCommerceProvider implements CommerceProvider
{
    public function __construct(private string $providerKey) {}

    public function key(): string
    {
        return $this->providerKey;
    }
}

final readonly class PartialInventoryProvider implements CommerceInventoryReadProvider
{
    public function __construct(private string $providerKey) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function inventory(
        CommerceProviderVariantReference $variant,
        ?string $scope = null,
    ): CommerceInventoryState {
        throw new LogicException('Not exercised by setup validation.');
    }
}

final readonly class CompleteCommerceAuthorityProvider implements
    CommerceOrderProvider,
    CommerceInventoryReadProvider,
    CommerceInventoryAdjustmentProvider
{
    public function __construct(private string $providerKey) {}

    public function key(): string
    {
        return $this->providerKey;
    }

    public function order(
        string $externalOrderId,
        ?string $scope = null,
    ): CommerceOrderSnapshotData {
        throw new LogicException('Not exercised by setup validation.');
    }

    public function inventory(
        CommerceProviderVariantReference $variant,
        ?string $scope = null,
    ): CommerceInventoryState {
        throw new LogicException('Not exercised by setup validation.');
    }

    public function adjustInventory(
        CommerceInventoryAdjustmentRequest $request,
    ): CommerceInventoryAdjustmentResult {
        throw new LogicException('Not exercised by setup validation.');
    }
}
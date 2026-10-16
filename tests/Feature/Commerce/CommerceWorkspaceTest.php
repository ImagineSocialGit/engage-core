<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductProviderMapping;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['commerce']);
        config()->set('commerce.provider_roles.'.CommerceProviderRole::Catalog->value, [
            'default' => 'shopify',
            'scopes' => [],
        ]);
        config()->set('commerce.provider_roles.'.CommerceProviderRole::Inventory->value, [
            'default' => 'shopify',
            'scopes' => [],
        ]);
    }


    public function test_commerce_navigation_is_exposed_only_when_commerce_is_enabled(): void
    {
        $enabled = collect(app(ModuleManager::class)->navigationItems())
            ->firstWhere('module', 'commerce');

        $this->assertIsArray($enabled);
        $this->assertSame('crm.commerce.index', $enabled['route']);

        config()->set('modules.enabled', []);

        $disabled = collect(app(ModuleManager::class)->navigationItems())
            ->firstWhere('module', 'commerce');

        $this->assertNull($disabled);
    }

    private function inventoryEffect(
        CommerceProductVariant $variant,
        string $quantityDelta,
        string $idempotencyKey,
    ): CommerceInventoryEffect {
        return CommerceInventoryEffect::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'source_type' => 'provider',
            'source_key' => 'shopify',
            'source_reference' => 'order-100',
            'reason' => 'completed_sale',
            'quantity_delta' => $quantityDelta,
            'authority_mode' => CommerceInventoryAuthorityMode::AuthorityAlreadyApplied,
            'inventory_scope' => null,
            'status' => CommerceInventoryEffect::STATUS_RECONCILED,
            'idempotency_key' => $idempotencyKey,
            'payload_fingerprint' => hash('sha256', $idempotencyKey),
            'occurred_at' => now(),
        ]);
    }
}
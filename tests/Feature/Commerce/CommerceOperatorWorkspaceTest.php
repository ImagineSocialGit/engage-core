<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Contracts\CommerceInventoryReadProvider;
use App\Modules\Commerce\Contracts\CommerceOrderProvider;
use App\Modules\Commerce\Data\CommerceInventoryState;
use App\Modules\Commerce\Data\CommerceOrderSnapshotData;
use App\Modules\Commerce\Data\CommerceProviderVariantReference;
use App\Modules\Commerce\Enums\CommerceProviderRole;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductProviderMapping;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Models\CommerceProductVariantProviderMapping;
use App\Modules\Commerce\Services\CommerceProviderRegistry;
use App\Modules\Commerce\Services\CommerceProviderRoleResolver;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceOperatorWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['commerce']);
        config()->set('commerce.provider_roles.'.CommerceProviderRole::Catalog->value, [
            'default' => 'fixture',
            'scopes' => [],
        ]);
        config()->set('commerce.provider_roles.'.CommerceProviderRole::Orders->value, [
            'default' => 'fixture',
            'scopes' => [],
        ]);
        config()->set('commerce.provider_roles.'.CommerceProviderRole::Inventory->value, [
            'default' => 'fixture',
            'scopes' => [],
        ]);
    }

    public function test_catalog_and_order_filters_narrow_normalized_workspace_results(): void
    {
        $owner = User::factory()->create();
        $this->profile($owner, 'owner');

        $tour = CommerceProduct::factory()->active()->create([
            'name' => 'Tour Shirt',
            'vendor' => 'Example Vendor',
        ]);
        $tourVariant = CommerceProductVariant::factory()
            ->for($tour, 'commerceProduct')
            ->active()
            ->create(['sku' => 'TOUR-M']);
        CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $tourVariant->getKey(),
            'provider_key' => 'fixture',
            'reference_type' => 'product_variant',
            'external_id' => 'variant-tour',
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
        ]);

        $poster = CommerceProduct::factory()->create([
            'name' => 'Archive Poster',
            'status' => CommerceProduct::STATUS_DRAFT,
        ]);
        CommerceProductProviderMapping::query()->create([
            'commerce_product_id' => $poster->getKey(),
            'provider_key' => 'other-provider',
            'reference_type' => 'catalog_product',
            'external_id' => 'poster-1',
            'status' => CommerceProductProviderMapping::STATUS_ACTIVE,
        ]);

        $catalog = $this->actingAs($owner)
            ->get(route('crm.commerce.index', [
                'q' => 'Tour',
                'status' => CommerceProduct::STATUS_ACTIVE,
                'provider' => 'fixture',
                'mapping' => 'inventory_mapped',
            ]))
            ->assertOk();

        $products = $catalog->viewData('products');
        $filters = $catalog->viewData('filters');

        $this->assertSame(1, $products->total());
        $this->assertSame((int) $tour->getKey(), (int) $products->first()->getKey());
        $this->assertSame('inventory_mapped', $filters['mapping']);

        $contact = Contact::factory()->create(['name' => 'Tour Buyer']);
        $confirmed = CommerceOrder::factory()->forContact($contact)->paid()->create([
            'provider' => 'fixture',
            'external_id' => 'order-confirmed',
            'order_name' => '#CONFIRMED',
        ]);
        CommerceOrderEvent::query()->create([
            'commerce_order_id' => $confirmed->getKey(),
            'event' => CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED,
            'occurred_at' => now(),
            'source' => 'commerce',
            'provider' => 'fixture',
            'external_id' => 'purchase-confirmed:filter-test',
            'payload' => null,
            'meta' => ['outcome_version' => 1],
        ]);
        CommerceOrder::factory()->create([
            'contact_id' => $contact->getKey(),
            'provider' => 'other-provider',
            'financial_status' => CommerceOrder::FINANCIAL_STATUS_REFUNDED,
            'external_id' => 'order-refunded',
            'order_name' => '#REFUNDED',
        ]);

        $ordersResponse = $this->actingAs($owner)
            ->get(route('crm.commerce.orders.index', [
                'q' => 'CONFIRMED',
                'financial_status' => CommerceOrder::FINANCIAL_STATUS_PAID,
                'provider' => 'fixture',
                'confirmation' => 'confirmed',
            ]))
            ->assertOk();

        $orders = $ordersResponse->viewData('orders');

        $this->assertSame(1, $orders->total());
        $this->assertSame((int) $confirmed->getKey(), (int) $orders->first()->getKey());
        $this->assertSame(1, $ordersResponse->viewData('summary')['purchase_confirmed']);
    }

    public function test_owner_can_refresh_one_order_and_read_one_variants_authoritative_inventory(): void
    {
        $owner = User::factory()->create();
        $this->profile($owner, 'owner');
        $contact = Contact::factory()->create();
        $order = CommerceOrder::factory()->forContact($contact)->create([
            'provider' => 'fixture',
            'external_id' => 'order-100',
            'total_cents' => 2500,
        ]);
        $product = CommerceProduct::factory()->active()->create();
        $variant = CommerceProductVariant::factory()
            ->for($product, 'commerceProduct')
            ->active()
            ->create();
        CommerceProductVariantProviderMapping::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'provider_key' => 'fixture',
            'reference_type' => 'product_variant',
            'external_id' => 'variant-100',
            'status' => CommerceProductVariantProviderMapping::STATUS_ACTIVE,
        ]);

        $provider = new CommerceOperatorFixtureProvider(
            snapshot: new CommerceOrderSnapshotData(
                externalId: 'order-100',
                orderNumber: '100',
                orderName: '#100',
                status: CommerceOrder::STATUS_CLOSED,
                financialStatus: CommerceOrder::FINANCIAL_STATUS_PAID,
                fulfillmentStatus: CommerceOrder::FULFILLMENT_STATUS_UNFULFILLED,
                currency: 'USD',
                subtotalCents: 4200,
                discountCents: 0,
                taxCents: 0,
                shippingCents: 0,
                totalCents: 4200,
                orderedAt: new DateTimeImmutable('2026-09-29T15:00:00Z'),
                closedAt: new DateTimeImmutable('2026-09-29T15:05:00Z'),
                cancelledAt: null,
                refundedAt: null,
                externalUrl: 'https://provider.example.test/orders/100',
                items: [],
            ),
        );
        $this->bindProvider($provider);

        $this->actingAs($owner)
            ->post(route('crm.commerce.products.variants.inventory', [$product, $variant]))
            ->assertRedirect(route('crm.commerce.products.show', $product))
            ->assertSessionHas('commerce_inventory_read.variant_id', (int) $variant->getKey())
            ->assertSessionHas('commerce_inventory_read.provider_key', 'fixture')
            ->assertSessionHas('commerce_inventory_read.available_quantity', 7);

        $this->actingAs($owner)
            ->post(route('crm.commerce.orders.refresh', $order))
            ->assertRedirect(route('crm.commerce.orders.show', $order));

        $order->refresh();

        $this->assertSame(4200, (int) $order->total_cents);
        $this->assertSame(CommerceOrder::STATUS_CLOSED, $order->status);
        $this->assertSame(1, $provider->orderReads);
        $this->assertSame(1, $provider->inventoryReads);
    }

    public function test_member_cannot_run_global_commerce_operator_actions(): void
    {
        $member = User::factory()->create();
        $this->profile($member, 'member');
        $contact = Contact::factory()->create([
            'assigned_user_id' => $member->getKey(),
        ]);
        $order = CommerceOrder::factory()->forContact($contact)->create([
            'provider' => 'fixture',
            'external_id' => 'order-member',
        ]);
        $product = CommerceProduct::factory()->active()->create();
        $variant = CommerceProductVariant::factory()
            ->for($product, 'commerceProduct')
            ->active()
            ->create();

        $this->bindProvider(new CommerceOperatorFixtureProvider(
            snapshot: new CommerceOrderSnapshotData(
                externalId: 'order-member',
                orderNumber: null,
                orderName: null,
                status: CommerceOrder::STATUS_OPEN,
                financialStatus: CommerceOrder::FINANCIAL_STATUS_PENDING,
                fulfillmentStatus: CommerceOrder::FULFILLMENT_STATUS_UNFULFILLED,
                currency: 'USD',
                subtotalCents: 1000,
                discountCents: 0,
                taxCents: 0,
                shippingCents: 0,
                totalCents: 1000,
                orderedAt: null,
                closedAt: null,
                cancelledAt: null,
                refundedAt: null,
                externalUrl: null,
                items: [],
            ),
        ));

        $this->actingAs($member)
            ->post(route('crm.commerce.orders.refresh', $order))
            ->assertForbidden();

        $this->actingAs($member)
            ->post(route('crm.commerce.products.variants.inventory', [$product, $variant]))
            ->assertForbidden();
    }

    private function bindProvider(CommerceOperatorFixtureProvider $provider): void
    {
        $this->app->instance(
            CommerceProviderRegistry::class,
            new CommerceProviderRegistry([$provider]),
        );
        $this->app->forgetInstance(CommerceProviderRoleResolver::class);
    }

    private function profile(User $user, string $role): UserAccessProfile
    {
        return UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => $role,
            'is_active' => true,
            'capability_overrides' => null,
        ]);
    }
}

final class CommerceOperatorFixtureProvider implements CommerceOrderProvider, CommerceInventoryReadProvider
{
    public int $orderReads = 0;
    public int $inventoryReads = 0;

    public function __construct(
        private readonly CommerceOrderSnapshotData $snapshot,
    ) {}

    public function key(): string
    {
        return 'fixture';
    }

    public function order(
        string $externalOrderId,
        ?string $scope = null,
    ): CommerceOrderSnapshotData {
        $this->orderReads++;

        return $this->snapshot;
    }

    public function inventory(
        CommerceProviderVariantReference $variant,
        ?string $scope = null,
    ): CommerceInventoryState {
        $this->inventoryReads++;

        return new CommerceInventoryState(
            providerKey: $this->key(),
            commerceProductVariantId: $variant->commerceProductVariantId,
            tracked: true,
            availableQuantity: 7,
            externalInventoryItemId: 'inventory-item-100',
            externalLocationId: 'location-100',
        );
    }
}
<?php

namespace Tests\Feature\Commerce;

use App\Models\User;
use App\Modules\Commerce\Enums\CommerceInventoryAuthorityMode;
use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceInventoryAdjustment;
use App\Modules\Commerce\Models\CommerceInventoryEffect;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use App\Modules\Commerce\Models\CommerceOrderItem;
use App\Modules\Commerce\Models\CommerceProduct;
use App\Modules\Commerce\Models\CommerceProductVariant;
use App\Modules\Commerce\Services\CommercePurchaseConfirmationService;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceOrderWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['commerce']);
    }

    public function test_order_index_and_detail_respect_contact_visibility(): void
    {
        $member = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->profile($member, 'member');

        $visibleContact = Contact::factory()->create([
            'assigned_user_id' => $member->getKey(),
        ]);
        $hiddenContact = Contact::factory()->create([
            'assigned_user_id' => $otherUser->getKey(),
        ]);

        $visibleOrder = $this->orderForContact($visibleContact, 'visible-order');
        $hiddenOrder = $this->orderForContact($hiddenContact, 'hidden-order');
        $unlinkedOrder = CommerceOrder::factory()->create([
            'commerce_customer_id' => null,
            'contact_id' => null,
            'external_id' => 'unlinked-order',
        ]);

        $response = $this->actingAs($member)
            ->get(route('crm.commerce.orders.index'))
            ->assertOk()
            ->assertViewIs('crm.commerce.orders.index');

        $orders = $response->viewData('orders');
        $summary = $response->viewData('summary');

        $this->assertSame(1, $orders->total());
        $this->assertSame((int) $visibleOrder->getKey(), (int) $orders->first()->getKey());
        $this->assertSame(1, $summary['total']);

        $this->actingAs($member)
            ->get(route('crm.commerce.orders.show', $visibleOrder))
            ->assertOk();

        $this->actingAs($member)
            ->get(route('crm.commerce.orders.show', $hiddenOrder))
            ->assertNotFound();

        $this->actingAs($member)
            ->get(route('crm.commerce.orders.show', $unlinkedOrder))
            ->assertNotFound();
    }

    public function test_order_detail_exposes_purchase_confirmation_inventory_and_lifecycle_evidence(): void
    {
        $owner = User::factory()->create();
        $this->profile($owner, 'owner');
        $contact = Contact::factory()->create();
        $customer = CommerceCustomer::factory()->create([
            'contact_id' => $contact->getKey(),
            'name' => 'Commerce Buyer',
            'provider' => 'shopify',
            'external_id' => 'customer-100',
        ]);
        $order = CommerceOrder::factory()
            ->forCustomer($customer)
            ->paid()
            ->create([
                'provider' => 'shopify',
                'source' => 'provider',
                'external_id' => 'order-100',
                'order_name' => '#100',
            ]);
        $product = CommerceProduct::factory()->active()->create();
        $variant = CommerceProductVariant::factory()
            ->for($product, 'commerceProduct')
            ->active()
            ->create();
        $item = CommerceOrderItem::factory()->create([
            'commerce_order_id' => $order->getKey(),
            'commerce_product_id' => $product->getKey(),
            'commerce_product_variant_id' => $variant->getKey(),
            'provider' => 'shopify',
            'external_id' => 'line-100',
        ]);

        CommerceOrderEvent::factory()->create([
            'commerce_order_id' => $order->getKey(),
            'event' => CommerceOrderEvent::EVENT_PAID,
            'source' => 'provider',
            'provider' => 'shopify',
            'external_id' => 'delivery-paid-100',
            'meta' => ['provider_event_type' => 'orders/paid'],
        ]);

        $confirmation = app(CommercePurchaseConfirmationService::class)
            ->confirm((int) $order->getKey());

        $this->assertNotNull($confirmation);

        $effect = CommerceInventoryEffect::query()->create([
            'commerce_product_variant_id' => $variant->getKey(),
            'source_type' => 'commerce_order_item',
            'source_key' => 'order-line-effect',
            'source_reference' => 'order-100',
            'reason' => 'authoritative_order_consumption',
            'quantity_delta' => '-1.0000',
            'authority_mode' => CommerceInventoryAuthorityMode::AuthorityAlreadyApplied,
            'inventory_scope' => null,
            'status' => CommerceInventoryEffect::STATUS_RECONCILED,
            'idempotency_key' => 'order-line-effect-1',
            'payload_fingerprint' => hash('sha256', 'order-line-effect-1'),
            'occurred_at' => now(),
            'meta' => [
                'orders_provider_key' => 'shopify',
                'inventory_provider_key' => 'shopify',
                'commerce_order_id' => (int) $order->getKey(),
                'commerce_order_item_id' => (int) $item->getKey(),
                'external_order_id' => 'order-100',
                'external_order_item_id' => 'line-100',
            ],
        ]);
        CommerceInventoryAdjustment::query()->create([
            'commerce_inventory_effect_id' => $effect->getKey(),
            'commerce_product_variant_provider_mapping_id' => null,
            'provider_key' => 'shopify',
            'quantity_delta' => '-1.0000',
            'status' => CommerceInventoryAdjustment::STATUS_SUCCEEDED,
            'idempotency_key' => 'adjustment-100',
            'external_id' => 'inventory-adjustment-100',
            'attempts' => 1,
            'requested_at' => now(),
            'completed_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->get(route('crm.commerce.orders.show', $order))
            ->assertOk()
            ->assertViewIs('crm.commerce.orders.show');

        $detail = $response->viewData('detail');

        $this->assertSame((int) $order->getKey(), (int) $detail['order']->getKey());
        $this->assertSame(1, $detail['items']->count());
        $this->assertSame('confirmed', $detail['purchase_confirmation']['state']);
        $this->assertSame(
            (int) $order->getKey(),
            $detail['purchase_confirmation']['confirmation']->commerceOrderId,
        );
        $this->assertSame(1, $detail['inventory_effects']->count());
        $this->assertSame(
            (int) $effect->getKey(),
            (int) $detail['inventory_effects_by_item'][$item->getKey()]->first()->getKey(),
        );
        $this->assertSame(1, $detail['inventory_effects']->first()->adjustments->count());
        $this->assertSame(2, $detail['events']->count());
    }

    public function test_invalid_purchase_confirmation_evidence_is_reported_without_breaking_order_inspection(): void
    {
        $owner = User::factory()->create();
        $this->profile($owner, 'owner');
        $order = CommerceOrder::factory()->paid()->create([
            'provider' => 'shopify',
            'external_id' => 'order-invalid-confirmation',
        ]);

        CommerceOrderEvent::query()->create([
            'commerce_order_id' => $order->getKey(),
            'event' => CommerceOrderEvent::EVENT_PURCHASE_CONFIRMED,
            'occurred_at' => now(),
            'source' => 'commerce',
            'provider' => 'shopify',
            'external_id' => 'purchase-confirmed:broken',
            'payload' => [
                'commerce_order_id' => (int) $order->getKey(),
            ],
            'meta' => ['outcome_version' => 1],
        ]);

        $detail = $this->actingAs($owner)
            ->get(route('crm.commerce.orders.show', $order))
            ->assertOk()
            ->viewData('detail');

        $this->assertSame('invalid', $detail['purchase_confirmation']['state']);
        $this->assertNull($detail['purchase_confirmation']['confirmation']);
        $this->assertNotSame('', trim((string) $detail['purchase_confirmation']['error']));
    }

    private function orderForContact(Contact $contact, string $externalId): CommerceOrder
    {
        $customer = CommerceCustomer::factory()->create([
            'contact_id' => $contact->getKey(),
        ]);

        return CommerceOrder::factory()
            ->forCustomer($customer)
            ->create([
                'external_id' => $externalId,
            ]);
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
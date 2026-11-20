<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Data\CommerceOrderCustomerSnapshotData;
use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Services\CommerceCustomerPrivacyService;
use App\Modules\Commerce\Services\CommerceOrderCustomerReconciler;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommerceCustomerPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_scoped_redaction_clears_commerce_pii_and_prevents_resync_repopulation(): void
    {
        $contact = Contact::factory()->create(['email' => 'crm@example.test']);
        $customer = CommerceCustomer::factory()->forContact($contact)->create([
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Customer/123',
            'email' => 'buyer@example.test',
            'meta' => ['provider_raw' => 'not retained'],
        ]);
        $order = CommerceOrder::factory()->forCustomer($customer)->create([
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Order/999',
        ]);

        $privacy = app(CommerceCustomerPrivacyService::class);
        $first = $privacy->redactProviderCustomer('shopify', 'gid://shopify/Customer/123');
        $again = $privacy->redactProviderCustomer('shopify', 'gid://shopify/Customer/123');

        $this->assertSame($first['customer_id'], $again['customer_id']);
        $this->assertTrue($first['commerce_redacted']);
        $this->assertNull($customer->fresh()->email);
        $this->assertNull($customer->fresh()->name);
        $this->assertNull($customer->fresh()->contact_id);
        $this->assertSame(CommerceCustomer::STATUS_ARCHIVED, $customer->fresh()->status);
        $this->assertNull($order->fresh()->contact_id);
        $this->assertSame('crm@example.test', $contact->fresh()->email);

        $resolution = app(CommerceOrderCustomerReconciler::class)->reconcile(
            'shopify',
            new CommerceOrderCustomerSnapshotData(
                externalId: 'gid://shopify/Customer/123',
                name: 'Replayed Name',
                email: 'replayed@example.test',
            ),
        );

        $this->assertSame((int) $customer->getKey(), $resolution->commerceCustomerId);
        $this->assertNull($resolution->contactId);
        $this->assertNull($customer->fresh()->email);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_unseen_customer_redaction_creates_a_pii_free_tombstone(): void
    {
        $privacy = app(CommerceCustomerPrivacyService::class);
        $result = $privacy->redactProviderCustomer('shopify', 'gid://shopify/Customer/802');

        $this->assertFalse($result['customer_found']);
        $this->assertTrue($result['commerce_redacted']);
        $customer = CommerceCustomer::query()->findOrFail($result['customer_id']);
        $this->assertNull($customer->email);
        $this->assertNull($customer->contact_id);
        $this->assertNotNull(data_get($customer->meta, 'privacy.redacted_at'));

        $resolution = app(CommerceOrderCustomerReconciler::class)->reconcile(
            'shopify',
            new CommerceOrderCustomerSnapshotData(
                externalId: 'gid://shopify/Customer/802',
                name: 'Should Never Return',
                email: 'should-not-return@example.test',
            ),
        );

        $this->assertNull($resolution->contactId);
        $this->assertNull($customer->fresh()->email);
        $this->assertSame(0, Contact::query()->count());
    }

    public function test_redacting_existing_order_retains_money_but_severs_customer_identity(): void
    {
        $contact = Contact::factory()->create();
        $customer = CommerceCustomer::factory()->forContact($contact)->create([
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Customer/202',
        ]);
        $order = CommerceOrder::factory()->forCustomer($customer)->create([
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Order/808',
            'total_cents' => 8900,
        ]);

        $privacy = app(CommerceCustomerPrivacyService::class);
        $result = $privacy->redactProviderOrder('shopify', 'gid://shopify/Order/808');

        $this->assertTrue($result['order_found']);
        $this->assertSame(8900, $order->fresh()->total_cents);
        $this->assertNull($order->fresh()->commerce_customer_id);
        $this->assertNull($order->fresh()->contact_id);
        $this->assertNotNull(data_get($order->fresh()->meta, 'privacy.redacted_at'));
    }

    public function test_unseen_order_redaction_creates_a_soft_deleted_tombstone(): void
    {
        $privacy = app(CommerceCustomerPrivacyService::class);
        $first = $privacy->redactProviderOrder('shopify', 'gid://shopify/Order/809');
        $second = $privacy->redactProviderOrder('shopify', 'gid://shopify/Order/809');

        $this->assertFalse($first['order_found']);
        $this->assertSame($first['order_id'], $second['order_id']);
        $this->assertSame(0, CommerceOrder::query()->count());
        $order = CommerceOrder::withTrashed()->findOrFail($first['order_id']);
        $this->assertTrue($order->trashed());
        $this->assertNull($order->contact_id);
        $this->assertNotNull(data_get($order->meta, 'privacy.redacted_at'));
    }

}

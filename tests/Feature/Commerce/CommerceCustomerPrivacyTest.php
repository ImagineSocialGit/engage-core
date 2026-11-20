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
}

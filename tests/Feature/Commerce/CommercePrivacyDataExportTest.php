<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Services\CommercePrivacyDataExportService;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommercePrivacyDataExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_exact_shopify_request_identities_are_exported_and_preparation_is_audited(): void
    {
        config()->set('client.key', 'lab');
        $customer = CommerceCustomer::factory()->create([
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Customer/502',
            'email' => 'merchant-customer@example.test',
            'name' => 'Merchant Customer',
        ]);
        CommerceOrder::factory()->forCustomer($customer)->create([
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Order/700',
            'order_number' => '700',
            'total_cents' => 3750,
        ]);
        CommerceOrder::factory()->forCustomer($customer)->create([
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Order/701',
            'total_cents' => 9800,
        ]);
        $receipt = app(WebhookInbox::class)->receiveVerified(
            provider: 'shopify',
            payload: ['customer' => ['id' => 502]],
            providerEventId: 'access-502',
            eventType: 'privacy.customers/data_request',
            storedPayload: [
                'shop_domain' => 'shop.example.myshopify.com',
                'customer_id' => '502',
                'order_ids' => ['700'],
                'data_request_id' => '91',
            ],
        );

        $report = app(CommercePrivacyDataExportService::class)
            ->prepare((int) $receipt->getKey(), 7);

        $this->assertSame('merchant-customer@example.test', $report['commerce_customer']['email']);
        $this->assertCount(1, $report['commerce_orders']);
        $this->assertSame('gid://shopify/Order/700', $report['commerce_orders'][0]['external_id']);
        $this->assertSame(3750, $report['commerce_orders'][0]['total_cents']);
        $this->assertNotNull(data_get($receipt->fresh()->outcome, 'commerce_export_prepared_at'));
        $this->assertSame('received', $receipt->fresh()->status);
    }

    public function test_cannot_export_another_clients_request_or_a_non_privacy_receipt(): void
    {
        config()->set('client.key', 'merchant-one');
        $receipt = app(WebhookInbox::class)->receiveVerified(
            provider: 'shopify',
            payload: ['customer' => ['id' => 99]],
            providerEventId: 'access-99',
            eventType: 'privacy.customers/data_request',
            storedPayload: ['customer_id' => '99', 'order_ids' => []],
        );
        config()->set('client.key', 'merchant-two');

        try {
            app(CommercePrivacyDataExportService::class)->prepare((int) $receipt->getKey(), 7);
            $this->fail('Cross-client data was exported.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertNull($receipt->fresh()->outcome);
    }
}

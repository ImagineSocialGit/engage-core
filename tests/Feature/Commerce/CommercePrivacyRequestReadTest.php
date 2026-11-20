<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Services\CommercePrivacyRequestReadService;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommercePrivacyRequestReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_query_only_returns_current_clients_privacy_receipts(): void
    {
        config()->set('client.key', 'test-client');

        $inbox = app(WebhookInbox::class);
        $privacy = $inbox->receiveVerified(
            provider: 'shopify',
            payload: ['customer' => ['id' => 1]],
            providerEventId: 'privacy-1',
            eventType: 'privacy.customers/redact',
            storedPayload: ['customer_id' => '1'],
        );
        $inbox->receiveVerified(
            provider: 'shopify',
            payload: ['order_id' => '3'],
            providerEventId: 'order-1',
            eventType: 'orders/paid',
        );
        config()->set('client.key', 'other-client');
        $inbox->receiveVerified(
            provider: 'shopify',
            payload: ['shop' => 'other'],
            providerEventId: 'privacy-2',
            eventType: 'privacy.shop/redact',
        );
        config()->set('client.key', 'test-client');

        $page = app(CommercePrivacyRequestReadService::class)->pending();
        $this->assertSame(1, $page->total());
        $this->assertSame($privacy->getKey(), $page->items()[0]->getKey());
    }

    public function test_review_requires_customer_redaction_before_closing_and_preserves_evidence(): void
    {
        config()->set('client.key', 'lab');
        $inbox = app(WebhookInbox::class);
        $receipt = $inbox->receiveVerified(
            provider: 'shopify',
            payload: ['customer' => ['id' => 12]],
            providerEventId: 'privacy-review-12',
            eventType: 'privacy.customers/redact',
            storedPayload: ['customer_id' => '12'],
        );

        $service = app(CommercePrivacyRequestReadService::class);
        try {
            $service->completeReviewed((int) $receipt->getKey(), 3, 'fulfilled', 'CASE-12');
            $this->fail('Expected a missing Commerce redaction review to block resolution.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $receipt->forceFill(['outcome' => ['commerce_scoped_reviewed_at' => now()->toIso8601String()]])->save();
        $service->completeReviewed((int) $receipt->getKey(), 3, 'fulfilled', 'CASE-12');

        $receipt->refresh();
        $this->assertSame('completed', $receipt->status);
        $this->assertSame('fulfilled', data_get($receipt->outcome, 'disposition'));
        $this->assertSame('CASE-12', data_get($receipt->outcome, 'case_reference'));
        $this->assertSame(3, data_get($receipt->outcome, 'merchant_reviewed_by_user_id'));
        $this->assertSame(0, $service->pending()->total());
    }

    public function test_data_request_fulfillment_requires_commerce_export_to_be_prepared(): void
    {
        config()->set('client.key', 'lab');
        $receipt = app(WebhookInbox::class)->receiveVerified(
            provider: 'shopify',
            payload: ['customer' => ['id' => 502]],
            providerEventId: 'privacy-access-502',
            eventType: 'privacy.customers/data_request',
            storedPayload: ['customer_id' => '502', 'order_ids' => []],
        );
        $service = app(CommercePrivacyRequestReadService::class);

        try {
            $service->completeReviewed((int) $receipt->getKey(), 3, 'fulfilled', 'ACCESS-502');
            $this->fail('Expected export preparation to be required.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        app(\App\Modules\Commerce\Services\CommercePrivacyDataExportService::class)
            ->prepare((int) $receipt->getKey(), 3);
        $service->completeReviewed((int) $receipt->getKey(), 3, 'fulfilled', 'ACCESS-502');

        $this->assertSame('completed', $receipt->fresh()->status);
        $this->assertNotNull(data_get($receipt->fresh()->outcome, 'commerce_export_prepared_at'));
    }

    public function test_order_only_redaction_request_requires_a_scoped_review(): void
    {
        config()->set('client.key', 'lab');
        $receipt = app(WebhookInbox::class)->receiveVerified(
            provider: 'shopify',
            payload: ['orders_to_redact' => [899]],
            providerEventId: 'privacy-order-899',
            eventType: 'privacy.customers/redact',
            storedPayload: ['customer_id' => null, 'order_ids' => ['899']],
        );
        $service = app(CommercePrivacyRequestReadService::class);

        try {
            $service->completeReviewed((int) $receipt->getKey(), 3, 'fulfilled', 'CASE-899');
            $this->fail('Expected order-only redaction review to be required.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
        app(\App\Modules\Commerce\Services\CommerceCustomerPrivacyService::class)
            ->redactProviderOrder('shopify', 'gid://shopify/Order/899');
        $receipt->forceFill(['outcome' => ['commerce_scoped_reviewed_at' => now()->toIso8601String()]])->save();
        $service->completeReviewed((int) $receipt->getKey(), 3, 'fulfilled', 'CASE-899');
        $this->assertSame('completed', $receipt->fresh()->status);
    }

    public function test_privacy_review_cannot_close_receipt_from_a_different_client(): void
    {
        config()->set('client.key', 'client-one');
        $receipt = app(WebhookInbox::class)->receiveVerified(
            provider: 'shopify',
            payload: ['customer' => ['id' => 9]],
            providerEventId: 'privacy-foreign',
            eventType: 'privacy.customers/data_request',
            storedPayload: ['customer_id' => '9'],
        );
        config()->set('client.key', 'client-two');

        try {
            app(CommercePrivacyRequestReadService::class)->completeReviewed(
                (int) $receipt->getKey(), 3, 'fulfilled', 'CASE-9',
            );
            $this->fail('Cross-client privacy closure was allowed.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $this->assertSame('received', $receipt->fresh()->status);
    }
}

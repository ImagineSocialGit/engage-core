<?php

namespace Tests\Feature\Commerce;

use App\Modules\Commerce\Services\CommercePrivacyReceiptRetentionService;
use App\Support\Webhooks\Models\WebhookInboxReceipt;
use App\Support\Webhooks\Services\WebhookInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CommercePrivacyReceiptRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_completed_requests_are_minimized_but_open_legal_hold_and_cross_client_records_survive(): void
    {
        config()->set('client.key', 'lab');
        $old = $this->receipt('old', 'fulfilled');
        $hold = $this->receipt('hold', 'legal_retention');
        $recent = $this->receipt('recent', 'fulfilled', 5);
        $pending = app(WebhookInbox::class)->receiveVerified(
            provider: 'shopify',
            payload: ['customer' => ['id' => 3]],
            providerEventId: 'pending',
            eventType: 'privacy.customers/redact',
            storedPayload: ['customer_id' => '3'],
        );
        config()->set('client.key', 'other');
        $foreign = $this->receipt('foreign', 'fulfilled');
        config()->set('client.key', 'lab');

        $scrubbed = app(CommercePrivacyReceiptRetentionService::class)->scrubResolved(90);

        $this->assertSame(1, $scrubbed);
        $this->assertSame(['identifiers_scrubbed' => true], $old->fresh()->payload);
        $this->assertNull(data_get($old->fresh()->outcome, 'case_reference'));
        $this->assertNotNull(data_get($old->fresh()->outcome, 'retention_scrubbed_at'));
        $this->assertSame('completed', $old->fresh()->status);
        $this->assertSame('1', data_get($hold->fresh()->payload, 'customer_id'));
        $this->assertSame('1', data_get($recent->fresh()->payload, 'customer_id'));
        $this->assertSame('3', data_get($pending->fresh()->payload, 'customer_id'));
        $this->assertSame('1', data_get($foreign->fresh()->payload, 'customer_id'));
        $this->assertSame(0, app(CommercePrivacyReceiptRetentionService::class)->scrubResolved(90));
    }

    public function test_older_legal_holds_do_not_starve_later_eligible_receipts(): void
    {
        config()->set('client.key', 'lab');
        $hold = $this->receipt('old-hold', 'legal_retention');
        $eligible = $this->receipt('next-eligible', 'fulfilled');

        $this->assertSame(1, app(CommercePrivacyReceiptRetentionService::class)->scrubResolved(90, 1));
        $this->assertSame('1', data_get($hold->fresh()->payload, 'customer_id'));
        $this->assertSame(['identifiers_scrubbed' => true], $eligible->fresh()->payload);
    }

    private function receipt(string $key, string $disposition, int $ageDays = 120): WebhookInboxReceipt
    {
        $receipt = app(WebhookInbox::class)->receiveVerified(
            provider: 'shopify',
            payload: ['customer' => ['id' => 1]],
            providerEventId: $key,
            eventType: 'privacy.customers/redact',
            storedPayload: ['customer_id' => '1', 'order_ids' => ['2']],
        );
        $receipt->forceFill([
            'status' => WebhookInboxReceipt::STATUS_COMPLETED,
            'completed_at' => now()->subDays($ageDays),
            'outcome' => [
                'case_reference' => 'CASE-'.$key,
                'disposition' => $disposition,
                'merchant_review_completed_at' => now()->subDays($ageDays)->toIso8601String(),
            ],
        ])->save();

        return $receipt;
    }
}

<?php

namespace App\Modules\Commerce\Services;

use App\Support\Webhooks\Models\WebhookInboxReceipt;
use Illuminate\Support\Facades\DB;

/**
 * Minimize identifiers in old, completed privacy request receipts while
 * preserving the receipt key/status for replay idempotency. Never touch
 * pending requests or cases with unresolved legal retention.
 */
final class CommercePrivacyReceiptRetentionService
{
    private const TOPICS = [
        'privacy.customers/data_request',
        'privacy.customers/redact',
        'privacy.shop/redact',
    ];

    public function scrubResolved(int $retentionDays, int $limit = 500): int
    {
        if ($retentionDays < 30 || $limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Invalid Commerce privacy receipt retention parameters.');
        }

        $clientKey = config('client.key');
        if (! is_string($clientKey) || trim($clientKey) === '') {
            return 0;
        }

        $cutoff = now()->subDays($retentionDays);
        $receiptIds = WebhookInboxReceipt::query()
            ->where('client_key', $clientKey)
            ->where('provider', 'shopify')
            ->whereIn('event_type', self::TOPICS)
            ->where('status', WebhookInboxReceipt::STATUS_COMPLETED)
            ->where('completed_at', '<', $cutoff)
            ->whereNull('claim_token')
            // Exclude already-scrubbed and held rows before the limit, or a
            // backlog of old holds could permanently starve eligible cases.
            ->whereNull('outcome->retention_scrubbed_at')
            ->where(function ($query): void {
                $query->whereNull('outcome->disposition')
                    ->orWhere('outcome->disposition', '!=', 'legal_retention');
            })
            ->where(function ($query): void {
                $query->whereNull('outcome->retention_hold')
                    ->orWhere('outcome->retention_hold', false);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $scrubbed = 0;
        foreach ($receiptIds as $id) {
            $scrubbed += DB::transaction(function () use ($id, $clientKey, $cutoff): int {
                $receipt = WebhookInboxReceipt::query()->whereKey($id)->lockForUpdate()->first();
                if (! $receipt instanceof WebhookInboxReceipt
                    || $receipt->client_key !== $clientKey
                    || $receipt->provider !== 'shopify'
                    || ! in_array($receipt->event_type, self::TOPICS, true)
                    || $receipt->status !== WebhookInboxReceipt::STATUS_COMPLETED
                    || $receipt->completed_at === null
                    || ! $receipt->completed_at->lt($cutoff)
                    || $receipt->claim_token !== null
                ) {
                    return 0;
                }

                $outcome = is_array($receipt->outcome) ? $receipt->outcome : [];
                if (data_get($outcome, 'disposition') === 'legal_retention'
                    || data_get($outcome, 'retention_hold') === true
                    || is_string(data_get($outcome, 'retention_scrubbed_at'))
                ) {
                    return 0;
                }

                $receipt->forceFill([
                    'payload' => ['identifiers_scrubbed' => true],
                    'outcome' => [
                        'disposition' => data_get($outcome, 'disposition'),
                        'merchant_review_completed_at' => data_get($outcome, 'merchant_review_completed_at'),
                        'retention_scrubbed_at' => now()->toIso8601String(),
                    ],
                    'last_error' => null,
                ])->save();

                return 1;
            }, 3);
        }

        return $scrubbed;
    }
}

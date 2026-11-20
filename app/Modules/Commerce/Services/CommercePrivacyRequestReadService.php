<?php

namespace App\Modules\Commerce\Services;

use App\Support\Webhooks\Models\WebhookInboxReceipt;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/** Scoped Shopify privacy request queue and attested operator case resolution. */
final class CommercePrivacyRequestReadService
{
    public function pending(int $perPage = 30): LengthAwarePaginator
    {
        return WebhookInboxReceipt::query()
            ->where('client_key', config('client.key'))
            ->where('provider', 'shopify')
            ->whereIn('event_type', [
                'privacy.customers/data_request',
                'privacy.customers/redact',
                'privacy.shop/redact',
            ])
            ->whereIn('status', [
                WebhookInboxReceipt::STATUS_RECEIVED,
                WebhookInboxReceipt::STATUS_RETRYABLE_FAILED,
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(max(1, min(100, $perPage)));
    }

    /**
     * Record an authorized operator's attested external resolution. This is a
     * record of completed merchant review, not automated cross-module erasure.
     */
    public function completeReviewed(
        int $receiptId,
        int $actorUserId,
        string $disposition,
        string $caseReference,
    ): void {
        $caseReference = trim($caseReference);
        if ($actorUserId <= 0 || $caseReference === '' || strlen($caseReference) > 120
            || ! in_array($disposition, ['fulfilled', 'legal_retention', 'not_applicable'], true)
        ) {
            throw new InvalidArgumentException('Invalid Shopify privacy review record.');
        }

        DB::transaction(function () use ($receiptId, $actorUserId, $disposition, $caseReference): void {
            $receipt = WebhookInboxReceipt::query()
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $receipt->provider === 'shopify'
                && $receipt->client_key === config('client.key')
                && in_array($receipt->event_type, [
                    'privacy.customers/data_request',
                    'privacy.customers/redact',
                    'privacy.shop/redact',
                ], true),
                404,
            );

            abort_unless(in_array($receipt->status, [
                WebhookInboxReceipt::STATUS_RECEIVED,
                WebhookInboxReceipt::STATUS_RETRYABLE_FAILED,
            ], true), 409);

            if ($receipt->event_type === 'privacy.customers/data_request') {
                abort_if($disposition === 'legal_retention', 422);
                // A verified request with identifiable Commerce records must
                // have a prepared merchant export before an operator attests
                // that the request was fulfilled. Delivery remains manual.
                if ($disposition === 'fulfilled'
                    && (data_get($receipt->payload, 'customer_id') !== null
                        || data_get($receipt->payload, 'order_ids', []) !== [])
                ) {
                    abort_unless(is_string(data_get($receipt->outcome, 'commerce_export_prepared_at')), 422);
                }
            }

            // Explicitly review the scoped Commerce customer before a final
            // redaction decision; this does not assert shared CRM erasure.
            if ($receipt->event_type === 'privacy.customers/redact'
                && (data_get($receipt->payload, 'customer_id') !== null
                    || data_get($receipt->payload, 'order_ids', []) !== [])
            ) {
                abort_unless(is_string(data_get($receipt->outcome, 'commerce_scoped_reviewed_at')), 422);
            }

            $receipt->forceFill([
                'status' => WebhookInboxReceipt::STATUS_COMPLETED,
                'completed_at' => now(),
                'claim_token' => null,
                'claim_expires_at' => null,
                'failed_at' => null,
                'last_error' => null,
                'outcome' => [
                    ...(is_array($receipt->outcome) ? $receipt->outcome : []),
                    'merchant_reviewed_by_user_id' => $actorUserId,
                    'merchant_review_completed_at' => now()->toIso8601String(),
                    'case_reference' => $caseReference,
                    'disposition' => $disposition,
                    'shared_data_and_retention_attested' => true,
                ],
            ])->save();
        }, 3);

        Log::channel('personal_data_access')->info('privacy_request_resolution_recorded', [
            'client_key' => (string) config('client.key', ''),
            'actor_user_id' => $actorUserId,
            'resource' => 'shopify_privacy_request',
            'record_id' => $receiptId,
            'disposition' => $disposition,
        ]);
    }
}

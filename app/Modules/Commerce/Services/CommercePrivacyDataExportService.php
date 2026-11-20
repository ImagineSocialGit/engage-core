<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Support\Webhooks\Models\WebhookInboxReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Merchant-controlled export of the exact Shopify customer/order identities in
 * a verified privacy request. Other CRM modules must be reviewed separately.
 */
final class CommercePrivacyDataExportService
{
    /** @return array<string, mixed> */
    public function prepare(int $receiptId, int $actorUserId): array
    {
        abort_unless($actorUserId > 0, 403);

        $report = DB::transaction(function () use ($receiptId, $actorUserId): array {
            $receipt = WebhookInboxReceipt::query()
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $receipt->provider === 'shopify'
                && $receipt->client_key === config('client.key')
                && $receipt->event_type === 'privacy.customers/data_request',
                404,
            );

            abort_unless(in_array($receipt->status, [
                WebhookInboxReceipt::STATUS_RECEIVED,
                WebhookInboxReceipt::STATUS_RETRYABLE_FAILED,
            ], true), 409);

            $customerId = data_get($receipt->payload, 'customer_id');
            $orderIds = data_get($receipt->payload, 'order_ids', []);
            abort_unless(is_array($orderIds), 422);
            abort_unless(
                (is_string($customerId) && preg_match('/^[1-9][0-9]*$/D', $customerId) === 1)
                || $orderIds !== [],
                422,
            );

            $customerGid = is_string($customerId)
                && preg_match('/^[1-9][0-9]*$/D', $customerId) === 1
                    ? 'gid://shopify/Customer/'.$customerId
                    : null;

            $customer = $customerGid !== null
                ? CommerceCustomer::withTrashed()
                    ->where('provider', 'shopify')
                    ->where('external_id', $customerGid)
                    ->first()
                : null;

            $orderGids = [];
            foreach (array_unique($orderIds) as $orderId) {
                abort_unless(is_string($orderId) && preg_match('/^[1-9][0-9]*$/D', $orderId) === 1, 422);
                $orderGids[] = 'gid://shopify/Order/'.$orderId;
            }

            $orders = $orderGids === []
                ? collect()
                : CommerceOrder::withTrashed()
                    ->with(['items' => fn ($query) => $query->orderBy('id')])
                    ->where('provider', 'shopify')
                    ->whereIn('external_id', $orderGids)
                    ->orderBy('id')
                    ->get();

            $report = [
                'request' => [
                    'receipt_id' => (int) $receipt->getKey(),
                    'shop_domain' => data_get($receipt->payload, 'shop_domain'),
                    'data_request_id' => data_get($receipt->payload, 'data_request_id'),
                    'customer_id' => $customerGid,
                    'requested_order_ids' => $orderGids,
                ],
                'commerce_customer' => $customer instanceof CommerceCustomer ? [
                    'external_id' => (string) $customer->external_id,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'name' => $customer->name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                    'status' => $customer->status,
                    'total_orders' => $customer->total_orders,
                    'total_spent_cents' => $customer->total_spent_cents,
                ] : null,
                'commerce_orders' => $orders->map(static fn (CommerceOrder $order): array => [
                    'external_id' => (string) $order->external_id,
                    'order_number' => $order->order_number,
                    'order_name' => $order->order_name,
                    'status' => $order->status,
                    'financial_status' => $order->financial_status,
                    'fulfillment_status' => $order->fulfillment_status,
                    'currency' => $order->currency,
                    'subtotal_cents' => $order->subtotal_cents,
                    'tax_cents' => $order->tax_cents,
                    'shipping_cents' => $order->shipping_cents,
                    'total_cents' => $order->total_cents,
                    'ordered_at' => $order->ordered_at?->toIso8601String(),
                    'items' => $order->items->map(static fn ($item): array => [
                        'name' => $item->name,
                        'sku' => $item->sku,
                        'variant_title' => $item->variant_title,
                        'options' => $item->options,
                        'quantity' => $item->quantity,
                        'total_cents' => $item->total_cents,
                    ])->all(),
                ])->all(),
                'manual_review_required' => [
                    'linked_crm_contact',
                    'messaging_and_consent',
                    'documents_and_attachments',
                    'other_enabled_modules_and_processors',
                    'legal_retention_and_backup_policy',
                ],
            ];

            $receipt->forceFill([
                'outcome' => [
                    ...(is_array($receipt->outcome) ? $receipt->outcome : []),
                    'commerce_export_prepared_at' => now()->toIso8601String(),
                    'commerce_export_prepared_by_user_id' => $actorUserId,
                ],
            ])->save();

            return $report;
        }, 3);

        Log::channel('personal_data_access')->info('privacy_commerce_export_prepared', [
            'client_key' => (string) config('client.key', ''),
            'actor_user_id' => $actorUserId,
            'resource' => 'shopify_privacy_request',
            'record_id' => $receiptId,
        ]);

        return $report;
    }
}

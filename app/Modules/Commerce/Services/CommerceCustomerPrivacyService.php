<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Scoped Commerce record pseudonymization after an operator approves a request.
 * Does not claim to erase shared CRM contacts or legally retained order records.
 */
final class CommerceCustomerPrivacyService
{
    /** @return array{customer_found:bool,customer_id:?int,commerce_redacted:bool} */
    public function redactProviderCustomer(string $provider, string $externalCustomerId): array
    {
        $provider = trim($provider);
        $externalCustomerId = trim($externalCustomerId);

        if ($provider === '' || $externalCustomerId === '') {
            throw new InvalidArgumentException('Commerce privacy redaction requires an exact provider customer identity.');
        }

        return DB::transaction(function () use ($provider, $externalCustomerId): array {
            $customer = CommerceCustomer::withTrashed()
                ->where('provider', $provider)
                ->where('external_id', $externalCustomerId)
                ->lockForUpdate()
                ->first();

            $found = $customer instanceof CommerceCustomer;

            // An as-yet-unimported provider customer still needs an exact-ID
            // tombstone. Otherwise a later delayed webhook could recreate PII.
            if (! $found) {
                $customer = new CommerceCustomer([
                    'provider' => $provider,
                    'external_id' => $externalCustomerId,
                    'source' => 'privacy',
                    'status' => CommerceCustomer::STATUS_ARCHIVED,
                ]);
            }

            $redactedAt = data_get($customer->meta, 'privacy.redacted_at');
            $customer->forceFill([
                'contact_id' => null,
                'first_name' => null,
                'last_name' => null,
                'name' => null,
                'email' => null,
                'phone' => null,
                'external_url' => null,
                'raw_payload' => null,
                // Discard arbitrary upstream metadata, which may contain PII.
                'meta' => [
                    'privacy' => [
                        'redacted_at' => is_string($redactedAt) && $redactedAt !== ''
                            ? $redactedAt
                            : now()->toIso8601String(),
                    ],
                ],
                'status' => CommerceCustomer::STATUS_ARCHIVED,
            ])->save();

            // An order can be legally retained while its CRM identity link is removed.
            CommerceOrder::withTrashed()
                ->where('commerce_customer_id', $customer->getKey())
                ->whereNotNull('contact_id')
                ->update(['contact_id' => null]);

            return [
                'customer_found' => $found,
                'customer_id' => (int) $customer->getKey(),
                'commerce_redacted' => true,
            ];
        });
    }

    /**
     * Redact an exact order identity while retaining non-PII financial facts.
     * Missing orders become soft-deleted identity tombstones, not synthetic sales.
     * A later authoritative sync may populate financial facts but not buyer PII.
     *
     * @return array{order_found:bool,order_id:int}
     */
    public function redactProviderOrder(string $provider, string $externalOrderId): array
    {
        $provider = trim($provider);
        $externalOrderId = trim($externalOrderId);

        if ($provider === '' || $externalOrderId === '') {
            throw new InvalidArgumentException('Commerce privacy redaction requires an exact provider order identity.');
        }

        return DB::transaction(function () use ($provider, $externalOrderId): array {
            $order = CommerceOrder::withTrashed()
                ->where('provider', $provider)
                ->where('external_id', $externalOrderId)
                ->lockForUpdate()
                ->first();

            $found = $order instanceof CommerceOrder;
            if (! $found) {
                $order = new CommerceOrder([
                    'provider' => $provider,
                    'external_id' => $externalOrderId,
                    'source' => 'privacy',
                    'status' => CommerceOrder::STATUS_ARCHIVED,
                ]);
            }

            $redactedAt = data_get($order->meta, 'privacy.redacted_at');
            $meta = [
                'privacy' => [
                    'redacted_at' => is_string($redactedAt) && $redactedAt !== ''
                        ? $redactedAt
                        : now()->toIso8601String(),
                ],
            ];

            $order->forceFill([
                'commerce_customer_id' => null,
                'contact_id' => null,
                'raw_payload' => null,
                'meta' => $meta,
            ])->save();

            // Line-item quantities and pricing remain financial evidence;
            // unstructured customization/options metadata can contain PII.
            CommerceOrderItem::withTrashed()
                ->where('commerce_order_id', $order->getKey())
                ->update(['options' => null, 'raw_payload' => null, 'meta' => null]);

            if (! $found) {
                $order->delete();
            }

            return ['order_found' => $found, 'order_id' => (int) $order->getKey()];
        });
    }
}

<?php

namespace App\Modules\Commerce\Services;

use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceOrder;
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

            if (! $customer instanceof CommerceCustomer) {
                return ['customer_found' => false, 'customer_id' => null, 'commerce_redacted' => false];
            }

            $meta = is_array($customer->meta) ? $customer->meta : [];

            if (! is_string(data_get($meta, 'privacy.redacted_at'))) {
                $meta = [
                    'privacy' => [
                        // Retain the provider identity as a reimport tombstone.
                        'redacted_at' => now()->toIso8601String(),
                    ],
                ];

                $customer->forceFill([
                    'contact_id' => null,
                    'first_name' => null,
                    'last_name' => null,
                    'name' => null,
                    'email' => null,
                    'phone' => null,
                    'external_url' => null,
                    'raw_payload' => null,
                    'meta' => $meta,
                    'status' => CommerceCustomer::STATUS_ARCHIVED,
                ])->save();
            }

            // An order can be legally retained while its CRM identity link is removed.
            CommerceOrder::withTrashed()
                ->where('commerce_customer_id', $customer->getKey())
                ->whereNotNull('contact_id')
                ->update(['contact_id' => null]);

            return [
                'customer_found' => true,
                'customer_id' => (int) $customer->getKey(),
                'commerce_redacted' => true,
            ];
        });
    }
}

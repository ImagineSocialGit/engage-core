<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Models\BillingCollectionRequirement;
use App\Modules\Billing\Models\BillingPaymentRequest;
use App\Modules\Billing\Models\BillingPrice;
use App\Modules\Billing\Services\BillingCollectionAmountResolver;
use App\Modules\Core\Models\Contact;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CreateBillingPaymentRequestAction
{
    public function __construct(
        private readonly BillingCollectionAmountResolver $amountResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function handle(
        BillingPrice $price,
        ?Model $context = null,
        ?Contact $contact = null,
        array $meta = [],
    ): BillingPaymentRequest {
        if (! $price->exists || $price->getKey() === null) {
            throw new InvalidArgumentException(
                'Billing payment requests require a persisted price.',
            );
        }

        if (! $price->isActive()) {
            throw new DomainException(
                'Billing payment requests cannot be created from an inactive price.',
            );
        }

        if ($context !== null && (! $context->exists || $context->getKey() === null)) {
            throw new InvalidArgumentException(
                'Billing payment request context must be persisted when provided.',
            );
        }

        if ($contact !== null && (! $contact->exists || $contact->getKey() === null)) {
            throw new InvalidArgumentException(
                'Billing payment request Contact must be persisted when provided.',
            );
        }

        return DB::transaction(function () use (
            $price,
            $context,
            $contact,
            $meta,
        ): BillingPaymentRequest {
            $lockedPrice = BillingPrice::query()
                ->whereKey($price->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $currency = strtoupper(trim((string) $lockedPrice->currency));

            if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
                throw new InvalidArgumentException(
                    'Billing prices require a three-letter currency code.',
                );
            }

            $requirement = BillingCollectionRequirement::query()
                ->where('billing_price_id', $lockedPrice->getKey())
                ->first();

            if (! $requirement instanceof BillingCollectionRequirement) {
                throw new DomainException(
                    'Billing payment requests require an explicit collection requirement.',
                );
            }

            $dueNowCents = $this->amountResolver->resolve(
                $lockedPrice,
                $requirement,
            );

            $totalCents = (int) $lockedPrice->amount_cents;
            $settled = $totalCents === 0;

            return BillingPaymentRequest::query()->create([
                'billing_price_id' => $lockedPrice->getKey(),
                'contact_id' => $contact?->getKey(),
                'context_type' => $context?->getMorphClass(),
                'context_id' => $context?->getKey(),
                'status' => $settled
                    ? BillingPaymentRequest::STATUS_SETTLED
                    : BillingPaymentRequest::STATUS_OPEN,
                'collection_mode' => $requirement->mode,
                'currency' => $currency,
                'total_cents' => $totalCents,
                'due_now_cents' => $dueNowCents,
                'paid_cents' => 0,
                'settled_at' => $settled ? now() : null,
                'meta' => $meta !== [] ? $meta : null,
            ]);
        });
    }
}
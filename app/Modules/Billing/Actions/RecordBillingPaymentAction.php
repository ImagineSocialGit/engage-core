<?php

namespace App\Modules\Billing\Actions;

use App\Modules\Billing\Models\BillingPayment;
use App\Modules\Billing\Models\BillingPaymentRequest;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class RecordBillingPaymentAction
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function handle(
        BillingPaymentRequest $paymentRequest,
        string $providerKey,
        string $externalId,
        string $status,
        int $amountCents,
        string $currency,
        ?DateTimeInterface $occurredAt = null,
        array $meta = [],
    ): BillingPayment {
        if (! $paymentRequest->exists || $paymentRequest->getKey() === null) {
            throw new InvalidArgumentException(
                'Billing payment recording requires a persisted payment request.',
            );
        }

        $providerKey = trim($providerKey);
        $externalId = trim($externalId);
        $currency = strtoupper(trim($currency));

        if ($providerKey === '' || $externalId === '') {
            throw new InvalidArgumentException(
                'Billing payments require provider and external identities.',
            );
        }

        if (! in_array($status, [
            BillingPayment::STATUS_PENDING,
            BillingPayment::STATUS_SUCCEEDED,
            BillingPayment::STATUS_FAILED,
            BillingPayment::STATUS_CANCELED,
        ], true)) {
            throw new InvalidArgumentException(
                "Unsupported Billing payment status [{$status}].",
            );
        }

        if ($amountCents < 1) {
            throw new InvalidArgumentException(
                'Billing payments require a positive amount.',
            );
        }

        if (preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new InvalidArgumentException(
                'Billing payment currency must be a three-letter currency code.',
            );
        }

        if ($currency !== strtoupper((string) $paymentRequest->currency)) {
            throw new DomainException(
                'Billing payment currency must match the payment request currency.',
            );
        }

        return DB::transaction(function () use (
            $paymentRequest,
            $providerKey,
            $externalId,
            $status,
            $amountCents,
            $currency,
            $occurredAt,
            $meta,
        ): BillingPayment {
            $lockedRequest = BillingPaymentRequest::query()
                ->whereKey($paymentRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $payment = BillingPayment::query()
                ->where('provider_key', $providerKey)
                ->where('external_id', $externalId)
                ->first();

            if ($payment instanceof BillingPayment) {
                $this->assertIdentityMatches(
                    payment: $payment,
                    paymentRequest: $lockedRequest,
                    amountCents: $amountCents,
                    currency: $currency,
                );

                $payment->forceFill([
                    'status' => $status,
                    'occurred_at' => $occurredAt ?? $payment->occurred_at,
                    'meta' => $meta !== [] ? $meta : $payment->meta,
                ])->save();
            } else {
                $payment = BillingPayment::query()->create([
                    'billing_payment_request_id' => $lockedRequest->getKey(),
                    'provider_key' => $providerKey,
                    'external_id' => $externalId,
                    'status' => $status,
                    'currency' => $currency,
                    'amount_cents' => $amountCents,
                    'occurred_at' => $occurredAt,
                    'meta' => $meta !== [] ? $meta : null,
                ]);
            }

            $paidCents = (int) BillingPayment::query()
                ->where('billing_payment_request_id', $lockedRequest->getKey())
                ->where('status', BillingPayment::STATUS_SUCCEEDED)
                ->sum('amount_cents');

            if ($paidCents > (int) $lockedRequest->total_cents) {
                throw new DomainException(
                    'Successful Billing payments cannot exceed the payment request total.',
                );
            }

            $settled = $paidCents >= (int) $lockedRequest->total_cents;

            $lockedRequest->forceFill([
                'paid_cents' => $paidCents,
                'status' => $settled
                    ? BillingPaymentRequest::STATUS_SETTLED
                    : BillingPaymentRequest::STATUS_OPEN,
                'settled_at' => $settled
                    ? ($lockedRequest->settled_at ?? now())
                    : null,
            ])->save();

            return $payment->refresh();
        });
    }

    private function assertIdentityMatches(
        BillingPayment $payment,
        BillingPaymentRequest $paymentRequest,
        int $amountCents,
        string $currency,
    ): void {
        if ((int) $payment->billing_payment_request_id !== (int) $paymentRequest->getKey()
            || (int) $payment->amount_cents !== $amountCents
            || strtoupper((string) $payment->currency) !== $currency
        ) {
            throw new DomainException(
                'Existing Billing payment identity does not match the supplied payment facts.',
            );
        }
    }
}
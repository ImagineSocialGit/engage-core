<?php

namespace Tests\Unit\Billing;

use App\Modules\Billing\Data\BillingCollectionRequest;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BillingCollectionCheckoutIntentTest extends TestCase
{
    public function test_legacy_callers_receive_the_provider_neutral_payment_intent(): void
    {
        $request = $this->request();

        $this->assertSame(BillingCollectionRequest::INTENT_PAYMENT, $request->checkoutIntent);
        $this->assertSame([], $request->meta);
    }

    public function test_business_caller_can_request_donation_presentation_without_provider_metadata(): void
    {
        $request = $this->request(BillingCollectionRequest::INTENT_DONATION);

        $this->assertSame(BillingCollectionRequest::INTENT_DONATION, $request->checkoutIntent);
        $this->assertSame(['label' => 'Example'], $request->meta);
    }

    public function test_unknown_intent_is_rejected_before_reaching_a_provider(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->request('stripe_donate');
    }

    private function request(?string $intent = null): BillingCollectionRequest
    {
        if ($intent === null) {
            return new BillingCollectionRequest(
                billingPaymentRequestId: 1,
                amountCents: 2500,
                currency: 'USD',
                successUrl: 'https://example.test/success',
                cancelUrl: 'https://example.test/cancel',
            );
        }

        return new BillingCollectionRequest(
            billingPaymentRequestId: 1,
            amountCents: 2500,
            currency: 'USD',
            successUrl: 'https://example.test/success',
            cancelUrl: 'https://example.test/cancel',
            customerEmail: 'donor@example.test',
            meta: ['label' => 'Example'],
            checkoutIntent: $intent,
        );
    }
}
<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Actions\CreateBillingPaymentRequestAction;
use App\Modules\Billing\Actions\RecordBillingPaymentAction;
use App\Modules\Billing\Contracts\BillingCollectionProvider;
use App\Modules\Billing\Data\BillingCollectionRequest;
use App\Modules\Billing\Data\BillingCollectionResult;
use App\Modules\Billing\Models\BillingCollectionRequirement;
use App\Modules\Billing\Models\BillingPayment;
use App\Modules\Billing\Models\BillingPaymentRequest;
use App\Modules\Billing\Models\BillingPrice;
use App\Modules\Billing\Providers\BillingModuleServiceProvider;
use App\Modules\Billing\Services\BillingCollectionAmountResolver;
use App\Modules\Billing\Services\BillingCollectionProviderRegistry;
use App\Modules\Core\Models\Contact;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_billing_module_is_registered_without_being_enabled_by_default(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'workflow',
            'flow_routes',
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'campaigns',
            'broadcasts',
            'webinars',
            'integrations',
            'reporting',
        ]);

        $modules = app(ModuleManager::class);

        $this->assertTrue($modules->known('billing'));
        $this->assertFalse($modules->enabled('billing'));
        $this->assertSame(['core'], $modules->dependencies('billing'));
        $this->assertContains(
            BillingModuleServiceProvider::class,
            $modules->providers('billing'),
        );
    }

    public function test_collection_modes_resolve_the_agreed_payment_shapes(): void
    {
        $resolver = app(BillingCollectionAmountResolver::class);

        $this->assertSame(0, $resolver->resolve(
            $this->price(0),
            $this->requirement(BillingCollectionRequirement::MODE_NONE),
        ));

        $this->assertSame(6000, $resolver->resolve(
            $this->price(6000),
            $this->requirement(BillingCollectionRequirement::MODE_FULL),
        ));

        $this->assertSame(50000, $resolver->resolve(
            $this->price(400000),
            $this->requirement(
                BillingCollectionRequirement::MODE_FIXED_DEPOSIT,
                fixedAmountCents: 50000,
            ),
        ));

        $this->assertSame(25000, $resolver->resolve(
            $this->price(100000),
            $this->requirement(
                BillingCollectionRequirement::MODE_PERCENTAGE_DEPOSIT,
                percentageBasisPoints: 2500,
            ),
        ));

        $this->assertSame(0, $resolver->resolve(
            $this->price(15000),
            $this->requirement(BillingCollectionRequirement::MODE_PAY_LATER),
        ));
    }

    public function test_payment_request_snapshots_price_and_collection_requirement(): void
    {
        $contact = Contact::factory()->create();

        $price = BillingPrice::query()->create([
            'billable_type' => $contact->getMorphClass(),
            'billable_id' => $contact->getKey(),
            'key' => 'training_program',
            'status' => BillingPrice::STATUS_ACTIVE,
            'currency' => 'USD',
            'amount_cents' => 400000,
        ]);

        $requirement = BillingCollectionRequirement::query()->create([
            'billing_price_id' => $price->getKey(),
            'mode' => BillingCollectionRequirement::MODE_FIXED_DEPOSIT,
            'fixed_amount_cents' => 50000,
        ]);

        $request = app(CreateBillingPaymentRequestAction::class)->handle(
            price: $price,
            context: $contact,
            contact: $contact,
        );

        $price->update(['amount_cents' => 500000]);
        $requirement->update(['fixed_amount_cents' => 100000]);

        $request->refresh();

        $this->assertSame(400000, $request->total_cents);
        $this->assertSame(50000, $request->due_now_cents);
        $this->assertSame(
            BillingCollectionRequirement::MODE_FIXED_DEPOSIT,
            $request->collection_mode,
        );
        $this->assertTrue($request->contact->is($contact));
        $this->assertTrue($request->context->is($contact));
        $this->assertSame(50000, $request->immediateRemainingCents());
        $this->assertSame(400000, $request->outstandingCents());
        $this->assertFalse($request->immediateCollectionSatisfied());
    }

    public function test_successful_payments_are_idempotent_and_keep_deposit_satisfaction_separate_from_full_settlement(): void
    {
        $contact = Contact::factory()->create();

        $price = BillingPrice::query()->create([
            'billable_type' => $contact->getMorphClass(),
            'billable_id' => $contact->getKey(),
            'key' => 'board_and_train',
            'status' => BillingPrice::STATUS_ACTIVE,
            'currency' => 'USD',
            'amount_cents' => 400000,
        ]);

        BillingCollectionRequirement::query()->create([
            'billing_price_id' => $price->getKey(),
            'mode' => BillingCollectionRequirement::MODE_FIXED_DEPOSIT,
            'fixed_amount_cents' => 50000,
        ]);

        $request = app(CreateBillingPaymentRequestAction::class)->handle(
            price: $price,
            contact: $contact,
        );

        $record = app(RecordBillingPaymentAction::class);

        $record->handle(
            paymentRequest: $request,
            providerKey: 'test-provider',
            externalId: 'payment-deposit',
            status: BillingPayment::STATUS_SUCCEEDED,
            amountCents: 50000,
            currency: 'USD',
        );

        $record->handle(
            paymentRequest: $request,
            providerKey: 'test-provider',
            externalId: 'payment-deposit',
            status: BillingPayment::STATUS_SUCCEEDED,
            amountCents: 50000,
            currency: 'USD',
        );

        $request->refresh();

        $this->assertSame(1, BillingPayment::query()->count());
        $this->assertSame(50000, $request->paid_cents);
        $this->assertTrue($request->immediateCollectionSatisfied());
        $this->assertSame(350000, $request->outstandingCents());
        $this->assertSame(BillingPaymentRequest::STATUS_OPEN, $request->status);
        $this->assertFalse($request->isSettled());

        $record->handle(
            paymentRequest: $request,
            providerKey: 'test-provider',
            externalId: 'payment-balance',
            status: BillingPayment::STATUS_SUCCEEDED,
            amountCents: 350000,
            currency: 'USD',
        );

        $request->refresh();

        $this->assertSame(400000, $request->paid_cents);
        $this->assertSame(0, $request->outstandingCents());
        $this->assertSame(BillingPaymentRequest::STATUS_SETTLED, $request->status);
        $this->assertTrue($request->isSettled());
        $this->assertNotNull($request->settled_at);
    }

    public function test_collection_provider_registry_exposes_provider_neutral_adapters(): void
    {
        $provider = new class implements BillingCollectionProvider
        {
            public function key(): string
            {
                return 'test-provider';
            }

            public function createCollection(
                BillingCollectionRequest $request,
            ): BillingCollectionResult {
                return new BillingCollectionResult(
                    providerKey: $this->key(),
                    externalCollectionId: 'checkout-1',
                    nextUrl: 'https://example.com/pay',
                );
            }
        };

        $registry = new BillingCollectionProviderRegistry([$provider]);

        $this->assertTrue($registry->has('test-provider'));
        $this->assertSame($provider, $registry->get('test-provider'));

        $result = $registry->get('test-provider')->createCollection(
            new BillingCollectionRequest(
                billingPaymentRequestId: 1,
                amountCents: 5000,
                currency: 'USD',
                successUrl: 'https://example.com/success',
                cancelUrl: 'https://example.com/cancel',
                customerEmail: 'customer@example.com',
            ),
        );

        $this->assertSame('test-provider', $result->providerKey);
        $this->assertSame('checkout-1', $result->externalCollectionId);
        $this->assertSame('https://example.com/pay', $result->nextUrl);
    }

    private function price(int $amountCents): BillingPrice
    {
        return new BillingPrice([
            'status' => BillingPrice::STATUS_ACTIVE,
            'currency' => 'USD',
            'amount_cents' => $amountCents,
        ]);
    }

    private function requirement(
        string $mode,
        ?int $fixedAmountCents = null,
        ?int $percentageBasisPoints = null,
    ): BillingCollectionRequirement {
        return new BillingCollectionRequirement([
            'mode' => $mode,
            'fixed_amount_cents' => $fixedAmountCents,
            'percentage_basis_points' => $percentageBasisPoints,
        ]);
    }
}
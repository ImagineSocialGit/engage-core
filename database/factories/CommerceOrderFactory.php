<?php

namespace Database\Factories;

use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommerceOrder>
 */
class CommerceOrderFactory extends Factory
{
    protected $model = CommerceOrder::class;

    public function definition(): array
    {
        return [
            'commerce_customer_id' => CommerceCustomer::factory(),
            'contact_id' => Contact::factory(),
            'order_number' => '1001',
            'order_name' => '#1001',
            'status' => CommerceOrder::STATUS_CLOSED,
            'financial_status' => CommerceOrder::FINANCIAL_STATUS_PAID,
            'fulfillment_status' => CommerceOrder::FULFILLMENT_STATUS_FULFILLED,
            'currency' => 'USD',
            'subtotal_cents' => 2500,
            'discount_cents' => 0,
            'tax_cents' => 0,
            'shipping_cents' => 0,
            'total_cents' => 2500,
            'ordered_at' => now(),
            'closed_at' => now(),
            'cancelled_at' => null,
            'refunded_at' => null,
            'source' => 'provider',
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Order/3001',
            'external_url' => null,
            'raw_payload' => null,
            'meta' => null,
        ];
    }

    public function forCustomer(CommerceCustomer $customer): self
    {
        return $this->state([
            'commerce_customer_id' => $customer->id,
            'contact_id' => $customer->contact_id,
        ]);
    }

    public function forContact(Contact $contact): self
    {
        return $this->state([
            'contact_id' => $contact->id,
        ]);
    }

    public function paid(): self
    {
        return $this->state([
            'status' => CommerceOrder::STATUS_CLOSED,
            'financial_status' => CommerceOrder::FINANCIAL_STATUS_PAID,
            'ordered_at' => now(),
            'closed_at' => now(),
        ]);
    }
}

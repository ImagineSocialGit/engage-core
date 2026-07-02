<?php

namespace Database\Factories;

use App\Modules\Commerce\Models\CommerceCustomer;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommerceCustomer>
 */
class CommerceCustomerFactory extends Factory
{
    protected $model = CommerceCustomer::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => null,
            'status' => CommerceCustomer::STATUS_ACTIVE,
            'currency' => 'USD',
            'first_ordered_at' => now()->subMonth(),
            'last_ordered_at' => now(),
            'total_orders' => 1,
            'total_spent_cents' => 2500,
            'source' => 'provider',
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Customer/1001',
            'external_url' => null,
            'raw_payload' => null,
            'meta' => null,
        ];
    }

    public function forContact(Contact $contact): self
    {
        return $this->state([
            'contact_id' => $contact->id,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ]);
    }
}

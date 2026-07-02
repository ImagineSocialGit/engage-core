<?php

namespace Database\Factories;

use App\Modules\Commerce\Models\CommerceOrder;
use App\Modules\Commerce\Models\CommerceOrderEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<CommerceOrderEvent>
 */
class CommerceOrderEventFactory extends Factory
{
    protected $model = CommerceOrderEvent::class;

    public function definition(): array
    {
        return [
            'commerce_order_id' => CommerceOrder::factory(),
            'actor_type' => null,
            'actor_id' => null,
            'event' => CommerceOrderEvent::EVENT_SYNCED,
            'from_status' => null,
            'to_status' => CommerceOrder::STATUS_CLOSED,
            'occurred_at' => now(),
            'source' => 'provider',
            'provider' => 'shopify',
            'external_id' => 'gid://shopify/Order/3001:updated',
            'payload' => null,
            'meta' => null,
        ];
    }

    public function actor(Model $actor): self
    {
        return $this->state([
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
        ]);
    }
}

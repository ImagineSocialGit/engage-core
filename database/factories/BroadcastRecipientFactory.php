<?php

namespace Database\Factories;

use App\Modules\Campaigns\Models\Broadcast;
use App\Modules\Campaigns\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BroadcastRecipient>
 */
class BroadcastRecipientFactory extends Factory
{
    protected $model = BroadcastRecipient::class;

    public function definition(): array
    {
        return [
            'broadcast_id' => Broadcast::factory(),
            'contact_id' => Contact::factory(),
            'status' => 'pending',
            'scheduled_message_ids' => null,
            'skip_reason' => null,
            'meta' => [],
        ];
    }
}
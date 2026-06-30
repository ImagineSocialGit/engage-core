<?php

namespace Database\Factories;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
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
            'status' => BroadcastRecipient::STATUS_PENDING,
            'scheduled_message_ids' => null,
            'skip_reason' => null,
            'meta' => [],
        ];
    }

    public function scheduled(array $scheduledMessageIds = [1]): static
    {
        return $this->state(fn (): array => [
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
            'scheduled_message_ids' => $scheduledMessageIds,
        ]);
    }

    public function skipped(?string $reason = 'not_eligible'): static
    {
        return $this->state(fn (): array => [
            'status' => BroadcastRecipient::STATUS_SKIPPED,
            'skip_reason' => $reason,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => BroadcastRecipient::STATUS_FAILED,
        ]);
    }
}
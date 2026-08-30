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
            'scheduled_message_id' => null,
            'sent_at' => null,
            'terminal_reason' => null,
            'meta' => [],
        ];
    }

    public function scheduled(?int $scheduledMessageId = null): static
    {
        return $this->state(fn (): array => [
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
            'scheduled_message_id' => $scheduledMessageId,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => BroadcastRecipient::STATUS_SENT,
            'sent_at' => now(),
            'terminal_reason' => null,
        ]);
    }

    public function skipped(?string $reason = 'not_eligible'): static
    {
        return $this->state(fn (): array => [
            'status' => BroadcastRecipient::STATUS_SKIPPED,
            'sent_at' => null,
            'terminal_reason' => $reason,
        ]);
    }

    public function failed(?string $reason = 'delivery_failed'): static
    {
        return $this->state(fn (): array => [
            'status' => BroadcastRecipient::STATUS_FAILED,
            'sent_at' => null,
            'terminal_reason' => $reason,
        ]);
    }
}
<?php

namespace Database\Factories;

use App\Modules\Campaigns\Models\Broadcast;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Broadcast>
 */
class BroadcastFactory extends Factory
{
    protected $model = Broadcast::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->sentence(3),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'status' => Broadcast::STATUS_DRAFT,
            'send_at' => null,
            'payload' => [
                'subject' => 'Test broadcast',
                'body' => 'This is a test broadcast.',
            ],
            'audience' => [
                'type' => 'all',
            ],
            'recipient_count' => 0,
            'scheduled_count' => 0,
            'cancelled_at' => null,
            'completed_at' => null,
            'meta' => [],
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => Broadcast::STATUS_SCHEDULED,
            'send_at' => now()->addHour(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => Broadcast::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => Broadcast::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }
}
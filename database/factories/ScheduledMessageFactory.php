<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledMessageFactory extends Factory
{
    protected $model = ScheduledMessage::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),

            'context_type' => null,
            'context_id' => null,

            'channel' => 'email',

            'purpose' => 'transactional',

            'scope' => 'general',

            'message_type' => 'message',

            'payload_class' => \App\Messaging\Payloads\EmailPayload::class,

            'payload' => [
                'to' => $this->faker->safeEmail(),
                'tokens' => [],
                'context' => [],
            ],

            'meta' => [],

            'status' => 'pending',

            'send_at' => now(),

            'sent_at' => null,
            'failed_at' => null,
            'skipped_at' => null,

            'failure_reason' => null,
        ];
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'channel' => 'email',
            'payload_class' => \App\Messaging\Payloads\EmailPayload::class,
        ]);
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'channel' => 'sms',
            'payload_class' => \App\Messaging\Payloads\SmsPayload::class,
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Failed'): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    public function skipped(string $reason = 'Skipped'): static
    {
        return $this->state(fn () => [
            'status' => 'skipped',
            'skipped_at' => now(),
            'failure_reason' => $reason,
        ]);
    }
}
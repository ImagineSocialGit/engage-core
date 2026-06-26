<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\InternalNotifications\Models\TeamMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class ScheduledMessageFactory extends Factory
{
    protected $model = ScheduledMessage::class;

    public function definition(): array
    {
        return [
            'recipient_type' => Contact::class,
            'recipient_id' => Contact::factory(),

            'context_type' => null,
            'context_id' => null,

            'channel' => 'email',

            'purpose' => 'transactional',

            'scope' => 'general',

            'message_type' => 'message',

            'payload_class' => \App\Modules\Messaging\Payloads\EmailPayload::class,

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

    public function forRecipient(Model $recipient): static
    {
        return $this->state(fn () => [
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
        ]);
    }

    public function forContact(?Contact $contact = null): static
    {
        return $this->forRecipient($contact ?? Contact::factory()->create());
    }

    public function forTeamMember(?TeamMember $teamMember = null): static
    {
        return $this->forRecipient($teamMember ?? TeamMember::factory()->create());
    }

    public function email(): static
    {
        return $this->state(fn () => [
            'channel' => 'email',
            'payload_class' => \App\Modules\Messaging\Payloads\EmailPayload::class,
        ]);
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'channel' => 'sms',
            'payload_class' => \App\Modules\Messaging\Payloads\SmsPayload::class,
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
<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
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
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,

            'flow_route_progress_id' => null,
            'flow_route_plan_id' => null,
            'flow_route_plan_item_id' => null,
            'flow_route_progress_item_id' => null,
            'flow_route_id' => null,
            'flow_route_point_id' => null,
            'flow_route_capability_id' => null,

            'channel' => 'email',
            'message_type' => 'message',

            'purpose' => 'transactional',
            'scope' => 'general',

            'payload_class' => EmailPayload::class,
            'queue' => 'emails',
            'dispatch_keys' => ['message'],
            'definition_config_path' => null,

            'payload' => [
                'to' => $this->faker->safeEmail(),
                'tokens' => [],
                'context' => [],
            ],

            'send_at' => now(),

            'status' => ScheduledMessage::STATUS_PENDING,

            'sent_at' => null,
            'failed_at' => null,
            'skipped_at' => null,

            'dedupe_key' => null,

            'failure_reason' => null,
            'skip_reason' => null,

            'meta' => [],
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
            'payload_class' => EmailPayload::class,
            'queue' => 'emails',
        ]);
    }

    public function sms(): static
    {
        return $this->state(fn () => [
            'channel' => 'sms',
            'payload_class' => SmsPayload::class,
            'queue' => 'notifications',
        ]);
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function failed(string $reason = 'Failed'): static
    {
        return $this->state(fn () => [
            'status' => ScheduledMessage::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    public function skipped(string $reason = 'Skipped'): static
    {
        return $this->state(fn () => [
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => $reason,
            'failure_reason' => null,
        ]);
    }
}

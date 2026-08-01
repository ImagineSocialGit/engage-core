<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Models\ScheduledMessageOutboxEvent;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
                'subject' => 'Scheduled message',
                'body' => 'Scheduled message body.',
            ],
            'send_at' => now(),
            'status' => ScheduledMessage::STATUS_PENDING,
            'provider_idempotency_key' => null,
            'dedupe_key' => null,
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

    public function sending(int $attempts = 1): static
    {
        $attempts = max(1, $attempts);

        return $this
            ->state(fn () => [
                'status' => ScheduledMessage::STATUS_SENDING,
                'provider_idempotency_key' => 'factory-'.Str::uuid(),
            ])
            ->afterCreating(function (ScheduledMessage $message) use ($attempts): void {
                for ($attemptNumber = 1; $attemptNumber < $attempts; $attemptNumber++) {
                    $completedAt = now()->subSeconds($attempts - $attemptNumber + 1);

                    ScheduledMessageDeliveryAttempt::query()->create([
                        'scheduled_message_id' => $message->getKey(),
                        'attempt_number' => $attemptNumber,
                        'claim_token' => (string) Str::uuid(),
                        'status' => ScheduledMessageDeliveryAttempt::STATUS_RELEASED,
                        'claimed_at' => $completedAt->copy()->subSecond(),
                        'lease_expires_at' => $completedAt,
                        'completed_at' => $completedAt,
                        'reason_code' => 'factory_retry_released',
                        'reason' => 'Factory-created released attempt.',
                    ]);
                }

                ScheduledMessageDeliveryAttempt::query()->create([
                    'scheduled_message_id' => $message->getKey(),
                    'attempt_number' => $attempts,
                    'claim_token' => (string) Str::uuid(),
                    'status' => ScheduledMessageDeliveryAttempt::STATUS_CLAIMED,
                    'claimed_at' => now(),
                    'lease_expires_at' => now()->addMinute(),
                ]);
            });
    }

    public function sent(): static
    {
        return $this->terminalWithAttempt(
            messageStatus: ScheduledMessage::STATUS_SENT,
            attemptStatus: ScheduledMessageDeliveryAttempt::STATUS_SENT,
            reasonCode: null,
            reason: null,
        );
    }

    public function failed(string $reason = 'Failed'): static
    {
        return $this->terminalWithAttempt(
            messageStatus: ScheduledMessage::STATUS_FAILED,
            attemptStatus: ScheduledMessageDeliveryAttempt::STATUS_FAILED,
            reasonCode: 'factory_failed',
            reason: $reason,
        );
    }

    public function skipped(string $reason = 'Skipped'): static
    {
        return $this
            ->state(fn () => [
                'status' => ScheduledMessage::STATUS_SKIPPED,
            ])
            ->afterCreating(function (ScheduledMessage $message) use ($reason): void {
                $occurredAt = now();

                ScheduledMessageOutboxEvent::query()->create([
                    'scheduled_message_id' => $message->getKey(),
                    'delivery_attempt_id' => null,
                    'event_type' => ScheduledMessage::STATUS_SKIPPED,
                    'occurred_at' => $occurredAt,
                    'reason_code' => 'factory_skipped',
                    'reason' => $reason,
                    'status' => ScheduledMessageOutboxEvent::STATUS_PUBLISHED,
                    'available_at' => $occurredAt,
                    'attempts' => 0,
                    'published_at' => $occurredAt,
                ]);
            });
    }

    private function terminalWithAttempt(
        string $messageStatus,
        string $attemptStatus,
        ?string $reasonCode,
        ?string $reason,
    ): static {
        return $this
            ->state(fn () => [
                'status' => $messageStatus,
                'provider_idempotency_key' => 'factory-'.Str::uuid(),
            ])
            ->afterCreating(function (ScheduledMessage $message) use (
                $messageStatus,
                $attemptStatus,
                $reasonCode,
                $reason,
            ): void {
                $occurredAt = now();
                $attempt = ScheduledMessageDeliveryAttempt::query()->create([
                    'scheduled_message_id' => $message->getKey(),
                    'attempt_number' => 1,
                    'claim_token' => (string) Str::uuid(),
                    'status' => $attemptStatus,
                    'claimed_at' => $occurredAt->copy()->subSecond(),
                    'lease_expires_at' => $occurredAt,
                    'provider_submission_started_at' => $occurredAt->copy()->subSecond(),
                    'completed_at' => $occurredAt,
                    'provider' => 'factory',
                    'provider_message_id' => $messageStatus === ScheduledMessage::STATUS_SENT
                        ? 'factory-message-'.$message->getKey()
                        : null,
                    'reason_code' => $reasonCode,
                    'reason' => $reason,
                ]);

                ScheduledMessageOutboxEvent::query()->create([
                    'scheduled_message_id' => $message->getKey(),
                    'delivery_attempt_id' => $attempt->getKey(),
                    'event_type' => $messageStatus,
                    'occurred_at' => $occurredAt,
                    'reason_code' => null,
                    'reason' => null,
                    'status' => ScheduledMessageOutboxEvent::STATUS_PUBLISHED,
                    'available_at' => $occurredAt,
                    'attempts' => 1,
                    'last_attempted_at' => $occurredAt,
                    'published_at' => $occurredAt,
                ]);
            });
    }
}
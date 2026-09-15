<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\HandleEmailProviderEventAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Models\ScheduledMessageOutboxEvent;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderSuppressionRuntimeSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_bounce_suppression_immediately_skips_pending_email_but_not_sms(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'bounce@example.test',
            'phone' => '+15551234567',
        ]);

        $pendingEmail = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'channel' => MessageChannel::Email->value,
                'status' => ScheduledMessage::STATUS_PENDING,
                'send_at' => now()->addDay(),
            ]);

        $pendingSms = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'channel' => MessageChannel::Sms->value,
                'status' => ScheduledMessage::STATUS_PENDING,
                'send_at' => now()->addDay(),
            ]);

        app(HandleEmailProviderEventAction::class)->handle([
            'type' => 'email.bounced',
            'data' => [
                'to' => ['bounce@example.test'],
            ],
        ], 'evt-bounce-runtime');

        $this->assertDatabaseHas('message_suppressions', [
            'channel' => MessageChannel::Email->value,
            'destination' => 'bounce@example.test',
            'reason' => MessageSuppression::REASON_BOUNCE,
            'released_at' => null,
        ]);

        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $pendingEmail->refresh()->status,
        );

        $this->assertSame(
            ScheduledMessage::STATUS_PENDING,
            $pendingSms->refresh()->status,
        );
    }

    public function test_contact_message_send_time_is_presented_in_client_timezone(): void
    {
        config()->set('client.timezone', 'America/Denver');

        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $sentAt = CarbonImmutable::parse('2026-09-15 18:00:00', 'UTC');

        $message = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'channel' => MessageChannel::Email->value,
                'status' => ScheduledMessage::STATUS_SENT,
                'send_at' => $sentAt,
            ]);

        $attempt = ScheduledMessageDeliveryAttempt::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'attempt_number' => 1,
            'claim_token' => (string) Str::uuid(),
            'status' => ScheduledMessageDeliveryAttempt::STATUS_SENT,
            'claimed_at' => $sentAt->subSecond(),
            'lease_expires_at' => $sentAt->addMinute(),
            'provider_submission_started_at' => $sentAt->subSecond(),
            'completed_at' => $sentAt,
            'provider' => 'resend',
            'provider_message_id' => 'timezone-test-message',
        ]);

        ScheduledMessageOutboxEvent::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'delivery_attempt_id' => $attempt->getKey(),
            'event_type' => ScheduledMessage::STATUS_SENT,
            'occurred_at' => $sentAt,
            'status' => ScheduledMessageOutboxEvent::STATUS_PUBLISHED,
            'available_at' => $sentAt,
            'attempts' => 1,
            'last_attempted_at' => $sentAt,
            'published_at' => $sentAt,
        ]);

        $this
            ->actingAs($user)
            ->get(route('crm.contacts.show', $contact))
            ->assertOk()
            ->assertSee('Sep 15, 2026 12:00 PM')
            ->assertDontSee('Sep 15, 2026 6:00 PM');
    }
}
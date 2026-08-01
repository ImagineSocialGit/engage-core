<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Models\ScheduledMessageOutboxEvent;
use App\Modules\Messaging\Services\ContactShow\ContactScheduledMessagesVisibilityDataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ContactScheduledMessagesTerminalResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_message_visibility_uses_the_durable_terminal_result_contract(): void
    {
        config()->set('client.timezone', 'UTC');
        Carbon::setTestNow(Carbon::parse('2026-08-01 03:00:00 UTC'));

        $contact = Contact::factory()->create();
        $message = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'status' => ScheduledMessage::STATUS_FAILED,
        ]);

        $attempt = ScheduledMessageDeliveryAttempt::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'attempt_number' => 2,
            'claim_token' => 'terminal-result-contact-visibility',
            'status' => ScheduledMessageDeliveryAttempt::STATUS_FAILED,
            'claimed_at' => now()->subMinutes(2),
            'lease_expires_at' => now()->subMinute(),
            'provider_submission_started_at' => now()->subMinutes(2),
            'completed_at' => now(),
            'provider' => 'contact_visibility_provider',
            'provider_message_id' => 'contact-visibility-provider-message',
            'reason_code' => 'provider_rejected',
            'reason' => 'Authoritative delivery-attempt failure.',
        ]);

        ScheduledMessageOutboxEvent::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'delivery_attempt_id' => $attempt->getKey(),
            'event_type' => ScheduledMessage::STATUS_FAILED,
            'occurred_at' => $attempt->completed_at,
            'status' => ScheduledMessageOutboxEvent::STATUS_PUBLISHED,
            'available_at' => $attempt->completed_at,
            'attempts' => 1,
            'last_attempted_at' => $attempt->completed_at,
            'published_at' => $attempt->completed_at,
        ]);

        $data = app(ContactScheduledMessagesVisibilityDataProvider::class)
            ->dataFor($contact);
        $item = data_get(
            $data,
            'contactVisibilitySections.scheduled_messages.items.0',
        );

        $this->assertIsArray($item);
        $this->assertSame('Failed', $item['status']);
        $this->assertSame(
            'Aug 1, 2026 3:00 AM',
            data_get($item, 'meta.Failed At'),
        );
        $this->assertSame(
            'Authoritative delivery-attempt failure.',
            data_get($item, 'meta.Failure'),
        );
    }
}
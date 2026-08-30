<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Models\ScheduledMessageOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BroadcastShowDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['broadcasts']);
    }

    public function test_recipient_workspace_is_paginated_and_filterable_by_terminal_status(): void
    {
        $user = User::factory()->create();
        $broadcast = Broadcast::factory()->scheduled()->create();

        BroadcastRecipient::factory()->count(55)->create([
            'broadcast_id' => $broadcast->getKey(),
            'status' => BroadcastRecipient::STATUS_PENDING,
        ]);
        BroadcastRecipient::factory()->sent()->count(3)->create([
            'broadcast_id' => $broadcast->getKey(),
        ]);

        $all = $this->actingAs($user)->get(route('crm.broadcasts.show', $broadcast));

        $all->assertOk();
        $all->assertViewHas('recipients', function ($recipients): bool {
            return $recipients->perPage() === 50
                && $recipients->total() === 58
                && $recipients->count() === 50;
        });
        $all->assertViewHas('recipientStatus', null);

        $sent = $this->actingAs($user)->get(route('crm.broadcasts.show', [
            'broadcast' => $broadcast,
            'recipient_status' => BroadcastRecipient::STATUS_SENT,
        ]));

        $sent->assertOk();
        $sent->assertViewHas('recipientStatus', BroadcastRecipient::STATUS_SENT);
        $sent->assertViewHas('recipients', function ($recipients): bool {
            return $recipients->total() === 3
                && $recipients->every(
                    fn (BroadcastRecipient $recipient): bool =>
                        $recipient->status === BroadcastRecipient::STATUS_SENT,
                );
        });
    }

    public function test_selected_delivery_issue_exposes_authoritative_attempt_details(): void
    {
        $user = User::factory()->create();
        $broadcast = Broadcast::factory()->completed()->create();
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);

        $message = ScheduledMessage::factory()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->getKey(),
            'status' => ScheduledMessage::STATUS_SKIPPED,
        ]);
        $attempt = ScheduledMessageDeliveryAttempt::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'attempt_number' => 1,
            'claim_token' => (string) Str::uuid(),
            'status' => ScheduledMessageDeliveryAttempt::STATUS_SKIPPED,
            'claimed_at' => now()->subSecond(),
            'lease_expires_at' => now()->addMinute(),
            'completed_at' => now(),
            'reason_code' => 'consent_missing',
            'reason' => 'Marketing permission is missing.',
        ]);
        ScheduledMessageOutboxEvent::query()->create([
            'scheduled_message_id' => $message->getKey(),
            'delivery_attempt_id' => $attempt->getKey(),
            'event_type' => ScheduledMessage::STATUS_SKIPPED,
            'occurred_at' => now(),
            'reason_code' => 'consent_missing',
            'reason' => 'Marketing permission is missing.',
            'status' => ScheduledMessageOutboxEvent::STATUS_PUBLISHED,
            'available_at' => now(),
            'published_at' => now(),
        ]);
        $recipient = BroadcastRecipient::factory()->skipped('Marketing permission is missing.')->create([
            'broadcast_id' => $broadcast->getKey(),
            'contact_id' => $contact->getKey(),
            'scheduled_message_id' => $message->getKey(),
        ]);

        $response = $this->actingAs($user)->get(route('crm.broadcasts.show', [
            'broadcast' => $broadcast,
            'delivery_issue' => $recipient->getKey(),
        ]));

        $response->assertOk();
        $response->assertViewHas('selectedDeliveryIssue', fn ($selected): bool =>
            $selected instanceof BroadcastRecipient && $selected->is($recipient)
        );
        $response->assertViewHas('selectedDeliveryIssueMessages', function ($messages) use ($message): bool {
            if ($messages->count() !== 1 || ! $messages->first()?->is($message)) {
                return false;
            }

            return $messages->first()->terminalOutboxEvent?->deliveryAttempt?->reason_code
                === 'consent_missing';
        });
    }
}
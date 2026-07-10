<?php

namespace Tests\Feature\Messaging;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResolvedMessageDispatchIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_action_persists_exact_send_time_and_behavior_owner(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-09 12:00:00');

        $contact = Contact::factory()->create(['email' => 'person@example.com']);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'general',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $broadcast = Broadcast::factory()->create();
        $sendAt = now()->addHour();

        $messages = app(DispatchMessageAction::class)->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'general',
            dispatchKeys: 'test_dispatch',
            sendAt: $sendAt,
            behaviorOwner: $broadcast,
            definitions: [[
                'dispatch_key' => 'test_dispatch',
                'message_type' => 'test_message',
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'general',
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'Hello',
                    'body' => 'Body',
                ],
            ]],
        );

        $this->assertCount(1, $messages);

        $message = ScheduledMessage::query()->firstOrFail();

        $this->assertTrue($message->send_at->equalTo($sendAt));
        $this->assertSame($broadcast->getMorphClass(), $message->behavior_owner_type);
        $this->assertSame($broadcast->getKey(), $message->behavior_owner_id);
        $this->assertTrue($message->behaviorOwner->is($broadcast));
        $this->assertSame($sendAt->toISOString(), data_get($message->meta, 'resolved_message_dispatch.resolved_send_at'));

        Queue::assertPushed(SendScheduledMessageJob::class);
    }

    public function test_behavior_owner_participates_in_dedupe_identity(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create(['email' => 'person@example.com']);
        
        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'general',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);
        
        $firstBroadcast = Broadcast::factory()->create();
        $secondBroadcast = Broadcast::factory()->create();
        $sendAt = now()->addHour();
        $definition = [[
            'dispatch_key' => 'test_dispatch',
            'message_type' => 'test_message',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'general',
            'payload_class' => EmailPayload::class,
            'queue' => 'notifications',
            'payload' => ['subject' => 'Hello', 'body' => 'Body'],
        ]];

        app(DispatchMessageAction::class)->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'general',
            dispatchKeys: 'test_dispatch',
            sendAt: $sendAt,
            behaviorOwner: $firstBroadcast,
            definitions: $definition,
        );

        app(DispatchMessageAction::class)->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'general',
            dispatchKeys: 'test_dispatch',
            sendAt: $sendAt,
            behaviorOwner: $secondBroadcast,
            definitions: $definition,
        );

        $this->assertDatabaseCount('scheduled_messages', 2);
        $this->assertCount(2, ScheduledMessage::query()->pluck('dedupe_key')->unique());
    }
}

<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BroadcastResolvedMessageDispatchOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcast_supplies_exact_send_at_and_itself_as_behavior_owner_without_fake_schedule(): void
    {
        Carbon::setTestNow('2026-07-09 12:00:00');
        $contact = Contact::factory()->create();
        $sendAt = now()->addHour();

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => Broadcast::DEFAULT_DISPATCH_KEY,
            'message_type' => Broadcast::DEFAULT_MESSAGE_TYPE,
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'send_at' => $sendAt,
            'recipient_filter' => ['type' => 'all'],
            'payload' => ['subject' => 'Update', 'body' => 'News'],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(function (...$arguments) use ($broadcast, $sendAt): bool {
                $resolvedSendAt = $arguments['sendAt'] ?? $arguments[12] ?? null;
                $behaviorOwner = $arguments['behaviorOwner'] ?? $arguments[13] ?? null;
                $definitions = $arguments['definitions'] ?? $arguments[11] ?? [];

                $this->assertInstanceOf(Carbon::class, $resolvedSendAt);
                $this->assertTrue($resolvedSendAt->equalTo($sendAt));
                $this->assertTrue($behaviorOwner->is($broadcast));
                $this->assertArrayNotHasKey('timing', $definitions[0]);
                $this->assertArrayNotHasKey('schedule', $definitions[0]);
                $this->assertArrayNotHasKey('conditions', $definitions[0]);

                return true;
            })
            ->andReturn([
                ScheduledMessage::factory()->create([
                    'recipient_type' => $contact->getMorphClass(),
                    'recipient_id' => $contact->getKey(),
                    'context_type' => $broadcast->getMorphClass(),
                    'context_id' => $broadcast->getKey(),
                    'behavior_owner_type' => $broadcast->getMorphClass(),
                    'behavior_owner_id' => $broadcast->getKey(),
                    'send_at' => $sendAt,
                ]),
            ]);

        app(ScheduleBroadcastAction::class)->handle($broadcast);
    }
}

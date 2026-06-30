<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_broadcast(): void
    {
        $user = User::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'user_id' => $user->id,
            'name' => 'June newsletter',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => 'broadcast_send',
            'message_type' => 'broadcast',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'subject' => 'Market update',
                'body' => 'Here is the latest market update.',
            ],
            'audience' => [
                'type' => 'tag',
                'tags' => ['homebuyer'],
            ],
            'recipient_count' => 10,
            'scheduled_count' => 0,
            'meta' => [
                'created_from' => 'test',
            ],
        ]);

        $this->assertSame('June newsletter', $broadcast->name);
        $this->assertSame('email', $broadcast->channel);
        $this->assertSame('marketing', $broadcast->purpose);
        $this->assertSame('broadcast', $broadcast->scope);
        $this->assertSame('broadcast_send', $broadcast->dispatch_key);
        $this->assertSame('broadcast', $broadcast->message_type);
        $this->assertSame(EmailPayload::class, $broadcast->payload_class);
        $this->assertSame('marketing', $broadcast->queue);
        $this->assertSame(Broadcast::STATUS_DRAFT, $broadcast->status);
        $this->assertSame('Market update', $broadcast->payload['subject']);
        $this->assertSame(['homebuyer'], $broadcast->audience['tags']);
        $this->assertSame('test', $broadcast->meta['created_from']);
    }

    public function test_it_has_recipients(): void
    {
        $broadcast = Broadcast::factory()->create();
        $contact = Contact::factory()->create();

        $recipient = BroadcastRecipient::factory()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        $this->assertTrue($broadcast->recipients->contains($recipient));
        $this->assertTrue($recipient->broadcast->is($broadcast));
        $this->assertTrue($recipient->contact->is($contact));
    }

    public function test_broadcast_recipient_stores_scheduled_message_ids(): void
    {
        $recipient = BroadcastRecipient::factory()
            ->scheduled([10, 11])
            ->create();

        $this->assertSame(BroadcastRecipient::STATUS_SCHEDULED, $recipient->status);
        $this->assertSame([10, 11], $recipient->scheduled_message_ids);
    }

    public function test_broadcast_can_resolve_scheduled_messages_by_context(): void
    {
        $broadcast = Broadcast::factory()->create();
        $contact = Contact::factory()->create();

        $scheduledMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
        ]);

        $this->assertTrue($broadcast->scheduledMessages->contains($scheduledMessage));
    }
}
<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BroadcastPersistenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcast_persistence_uses_private_template_and_singular_scheduled_message_relationships(): void
    {
        $this->assertTrue(Schema::hasColumns('broadcasts', [
            'message_template_id',
            'message_template_version_id',
        ]));
        $this->assertFalse(Schema::hasColumn('broadcasts', 'payload'));

        $this->assertTrue(Schema::hasColumn('broadcast_recipients', 'scheduled_message_id'));
        $this->assertFalse(Schema::hasColumn('broadcast_recipients', 'scheduled_message_ids'));

        $broadcast = Broadcast::factory()->withMessage([
            'subject' => 'Private draft',
            'body' => 'Stored once in Messaging.',
        ])->create();

        $this->assertNotNull($broadcast->message_template_id);
        $this->assertNull($broadcast->message_template_version_id);
        $this->assertSame('Private draft', $broadcast->messagePayload()['subject']);

        $scheduledMessage = ScheduledMessage::factory()->create();
        $recipient = BroadcastRecipient::factory()
            ->scheduled($scheduledMessage->getKey())
            ->create([
                'broadcast_id' => $broadcast->getKey(),
            ]);

        $this->assertSame($scheduledMessage->getKey(), $recipient->scheduled_message_id);
        $this->assertTrue($recipient->scheduledMessage->is($scheduledMessage));
    }
}
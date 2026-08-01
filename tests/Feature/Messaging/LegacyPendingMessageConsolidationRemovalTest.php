<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LegacyPendingMessageConsolidationRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_consolidation_metadata_cannot_mutate_an_existing_pending_delivery(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create([
            'email' => 'recipient@example.test',
        ]);
        $scheduleMessage = app(ScheduleMessageAction::class);
        $dedupeKey = 'fixture:legacy-pending-consolidation';

        $first = $scheduleMessage->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'fixture',
            messageType: 'fixture',
            payloadClass: EmailPayload::class,
            payload: [
                'to' => $contact->email,
                'subject' => 'Original subject',
                'body' => 'Original body.',
            ],
            sendAt: now()->addHour(),
            dedupeKey: $dedupeKey,
            queue: 'confirmation_messages',
        );

        $second = $scheduleMessage->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'fixture',
            messageType: 'fixture',
            payloadClass: EmailPayload::class,
            payload: [
                'to' => $contact->email,
                'subject' => 'Incoming subject',
                'body' => 'Incoming body.',
            ],
            sendAt: now()->addHour(),
            dedupeKey: $dedupeKey,
            meta: [
                'delivery_consolidation' => [
                    'policy' => 'legacy_fixture',
                    'payload_key' => 'body',
                    'position' => 'append',
                    'separator' => "\n\n",
                ],
            ],
            queue: 'confirmation_messages',
        );

        $first->refresh();

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertEquals([
            'to' => $contact->email,
            'subject' => 'Original subject',
            'body' => 'Original body.',
        ], $first->payload);
        $this->assertEquals([], $first->meta);
    }
}
<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduleBroadcastActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_schedules_a_broadcast_to_all_contacts_through_messaging(): void
    {
        $contacts = Contact::factory()->count(2)->create();

        $broadcast = Broadcast::factory()->create([
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'broadcast',
            'dispatch_key' => 'broadcast_send',
            'message_type' => 'broadcast',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'send_at' => now()->addHour(),
            'recipient_filter' => [
                'type' => 'all',
            ],
            'payload' => [
                'subject' => 'Monthly update',
                'body' => 'Here is the monthly update.',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->twice()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                        'channel' => 'email',
                        'purpose' => 'marketing',
                        'scope' => 'broadcast',
                        'message_type' => 'broadcast',
                        'payload_class' => EmailPayload::class,
                        'queue' => 'marketing',
                        'dispatch_keys' => ['broadcast_send'],
                        'payload' => [
                            'subject' => 'Monthly update',
                            'body' => 'Here is the monthly update.',
                        ],
                    ]),
                ];
            });

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $scheduledBroadcast->status);
        $this->assertSame(2, $scheduledBroadcast->recipient_count);
        $this->assertSame(2, $scheduledBroadcast->scheduled_count);

        foreach ($contacts as $contact) {
            $recipient = BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->id)
                ->where('contact_id', $contact->id)
                ->first();

            $this->assertNotNull($recipient);
            $this->assertSame(BroadcastRecipient::STATUS_SCHEDULED, $recipient->status);
            $this->assertCount(1, $recipient->scheduled_message_ids);
            $this->assertNull($recipient->skip_reason);
        }
    }

    public function test_it_schedules_a_broadcast_to_specific_contacts(): void
    {
        $included = Contact::factory()->create();
        $excluded = Contact::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'recipient_filter' => [
                'type' => 'contact_ids',
                'contact_ids' => [$included->id],
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertDatabaseHas('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $included->id,
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
        ]);

        $this->assertDatabaseMissing('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $excluded->id,
        ]);
    }

    public function test_it_schedules_a_broadcast_to_contacts_with_matching_tags(): void
    {
        $tagged = Contact::factory()->create();
        $untagged = Contact::factory()->create();

        ContactTag::query()->create([
            'contact_id' => $tagged->id,
            'tag' => 'homebuyer',
        ]);

        ContactTag::query()->create([
            'contact_id' => $untagged->id,
            'tag' => 'refinance',
        ]);

        $broadcast = Broadcast::factory()->create([
            'recipient_filter' => [
                'type' => 'tag',
                'tags' => ['homebuyer'],
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                    ]),
                ];
            });

        app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertDatabaseHas('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $tagged->id,
            'status' => BroadcastRecipient::STATUS_SCHEDULED,
        ]);

        $this->assertDatabaseMissing('broadcast_recipients', [
            'broadcast_id' => $broadcast->id,
            'contact_id' => $untagged->id,
        ]);
    }

    public function test_it_marks_a_recipient_skipped_when_messaging_schedules_nothing(): void
    {
        $contact = Contact::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturn([]);

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $recipient = BroadcastRecipient::query()->where('broadcast_id', $broadcast->id)->first();

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $scheduledBroadcast->status);
        $this->assertSame(1, $scheduledBroadcast->recipient_count);
        $this->assertSame(0, $scheduledBroadcast->scheduled_count);
        $this->assertSame($contact->id, $recipient->contact_id);
        $this->assertSame(BroadcastRecipient::STATUS_SKIPPED, $recipient->status);
        $this->assertNull($recipient->scheduled_message_ids);
        $this->assertSame('not_scheduled_by_messaging', $recipient->skip_reason);
    }

    public function test_it_applies_a_five_minute_buffer_to_send_now_broadcasts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

        Contact::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'send_at' => null,
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];
                $triggeredAt = $arguments['triggeredAt'] ?? $arguments[7];
                $meta = $arguments['meta'] ?? $arguments[9];

                $this->assertSame(
                    '2026-07-01 10:05:00',
                    Carbon::parse($triggeredAt)->toDateTimeString(),
                );

                $this->assertSame(
                    ScheduleBroadcastAction::SEND_BUFFER_MINUTES,
                    $meta['send_buffer_minutes'],
                );

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                        'send_at' => Carbon::parse($triggeredAt),
                    ]),
                ];
            });

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(
            '2026-07-01 10:05:00',
            $scheduledBroadcast->send_at->toDateTimeString(),
        );
    }

    public function test_it_keeps_a_future_scheduled_broadcast_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

        Contact::factory()->create();

        $futureSendAt = Carbon::parse('2026-07-01 11:00:00');

        $broadcast = Broadcast::factory()->create([
            'send_at' => $futureSendAt,
            'recipient_filter' => [
                'type' => 'all',
            ],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->once()
            ->andReturnUsing(function (...$arguments): array {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];
                $triggeredAt = $arguments['triggeredAt'] ?? $arguments[7];

                $this->assertSame(
                    '2026-07-01 11:00:00',
                    Carbon::parse($triggeredAt)->toDateTimeString(),
                );

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                        'send_at' => Carbon::parse($triggeredAt),
                    ]),
                ];
            });

        $scheduledBroadcast = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(
            '2026-07-01 11:00:00',
            $scheduledBroadcast->send_at->toDateTimeString(),
        );
    }
}
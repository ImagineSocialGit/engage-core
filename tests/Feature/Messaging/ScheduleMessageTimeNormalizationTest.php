<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageSendTimeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduleMessageTimeNormalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_time_is_normalized_to_utc_without_duplicate_scheduling_metadata(): void
    {
        config()->set('client.timezone', 'America/Chicago');

        Queue::fake();

        $contact = Contact::factory()->create();

        $resolvedSendAt = app(MessageSendTimeResolver::class)->resolve(
            definition: [
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'next_day_at',
                    'time' => '09:30',
                ],
            ],
            triggeredAt: Carbon::create(
                2026,
                7,
                19,
                16,
                0,
                0,
                'UTC',
            ),
            anchor: null,
        );

        $this->assertSame(
            'America/Chicago',
            $resolvedSendAt->getTimezone()->getName(),
        );
        $this->assertSame(
            '2026-07-20T09:30:00-05:00',
            $resolvedSendAt->toIso8601String(),
        );

        $message = app(ScheduleMessageAction::class)->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'general',
            messageType: 'time_normalization_test',
            payloadClass: EmailPayload::class,
            payload: [
                'to' => $contact->email,
                'subject' => 'Time normalization test',
                'body' => 'Time normalization test.',
            ],
            sendAt: $resolvedSendAt,
            meta: [
                'queue' => 'notifications',
                'source' => 'test',
            ],
        );

        $this->assertTrue(
            $message->send_at->equalTo($resolvedSendAt),
        );

        $this->assertSame(
            '2026-07-20 14:30:00',
            DB::table('scheduled_messages')
                ->where('id', $message->getKey())
                ->value('send_at'),
        );

        $this->assertEquals(['source' => 'test'], $message->meta);
        $this->assertArrayNotHasKey('message_scheduling', $message->meta);

        Queue::assertPushed(
            SendScheduledMessageJob::class,
            function (SendScheduledMessageJob $job) use ($message): bool {
                $this->assertSame(
                    $message->getKey(),
                    $job->scheduledMessageId,
                );

                $this->assertInstanceOf(
                    Carbon::class,
                    $job->delay,
                );

                $this->assertSame(
                    'UTC',
                    $job->delay->getTimezone()->getName(),
                );

                $this->assertSame(
                    '2026-07-20T14:30:00+00:00',
                    $job->delay->toIso8601String(),
                );

                $this->assertSame(
                    '2026-07-20T14:30:00+00:00',
                    $job->horizon['send_at'] ?? null,
                );

                return true;
            },
        );
    }
}
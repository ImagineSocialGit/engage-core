<?php

namespace Tests\Feature\Messaging;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\ResolvedMessageDispatchBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class ResolvedMessageDispatchBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_only_template_requires_explicit_behavior_or_exact_send_at(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires exact [sendAt] or explicit caller-owned [behavior]');

        app(ResolvedMessageDispatchBuilder::class)->build(
            template: $this->template(),
        );
    }

    public function test_explicit_immediate_behavior_resolves_now(): void
    {
        Carbon::setTestNow('2026-07-09 12:00:00');

        $dispatch = app(ResolvedMessageDispatchBuilder::class)->build(
            template: $this->template(),
            behavior: ['timing' => 'immediate'],
        );

        $this->assertSame('immediate', $dispatch->definition['timing']);
        $this->assertTrue($dispatch->sendAt->equalTo(now()));
    }

    public function test_caller_owned_behavior_resolves_scheduled_send_time(): void
    {
        Carbon::setTestNow('2026-07-09 12:00:00');

        $dispatch = app(ResolvedMessageDispatchBuilder::class)->build(
            template: $this->template(),
            triggeredAt: now(),
            behavior: [
                'timing' => 'scheduled',
                'schedule' => [
                    'type' => 'delay',
                    'minutes' => 15,
                ],
                'conditions' => [
                    ['field' => 'contact.source', 'operator' => 'equals', 'value' => 'webinar'],
                ],
            ],
        );

        $this->assertSame('scheduled', $dispatch->definition['timing']);
        $this->assertEquals(
            ['type' => 'delay', 'minutes' => 15],
            $dispatch->definition['schedule'],
        );
        $this->assertSame(now()->addMinutes(15)->toISOString(), $dispatch->sendAt->toISOString());
    }

    public function test_explicit_send_at_wins_without_fake_schedule_metadata(): void
    {
        $sendAt = Carbon::parse('2026-07-09 15:30:00');
        $broadcast = Broadcast::factory()->create();

        $dispatch = app(ResolvedMessageDispatchBuilder::class)->build(
            template: $this->template(),
            sendAt: $sendAt,
            behaviorOwner: $broadcast,
        );

        $this->assertTrue($dispatch->sendAt->equalTo($sendAt));
        $this->assertArrayNotHasKey('timing', $dispatch->definition);
        $this->assertArrayNotHasKey('schedule', $dispatch->definition);
        $this->assertTrue($dispatch->behaviorOwner->is($broadcast));
        $this->assertEquals([], $dispatch->meta);
    }

    public function test_it_rejects_scheduled_behavior_without_schedule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Resolved scheduled message dispatch is missing [schedule].');

        app(ResolvedMessageDispatchBuilder::class)->build(
            template: $this->template(),
            behavior: ['timing' => 'scheduled'],
        );
    }


    public function test_it_rejects_behavior_fields_inside_reusable_template_data(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must not own [timing]');

        app(ResolvedMessageDispatchBuilder::class)->build(
            template: $this->template() + ['timing' => 'immediate'],
            sendAt: now(),
        );
    }
    /** @return array<string, mixed> */
    private function template(): array
    {
        return [
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'notifications',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'Registered',
                'body' => 'Thanks for registering.',
            ],
        ];
    }
}
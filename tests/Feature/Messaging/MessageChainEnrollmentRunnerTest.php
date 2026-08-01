<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ProcessMessageChainEnrollmentAction;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Actions\StartMessageChainEnrollmentAction;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Jobs\ProcessDueMessageChainEnrollmentsJob;
use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\MessageChainTimingResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageChainEnrollmentRunnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_materializes_only_the_current_variant_wave_and_advances_after_the_step_policy_is_satisfied(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00 UTC');
        Queue::fake();

        $contact = $this->contactWithConsent(
            channels: ['email', 'sms'],
        );
        $emailVersion = $this->templateVersion(
            key: 'email.transactional.webinar.fixture_runner',
            channel: 'email',
            payload: [
                'subject' => 'Fixture runner',
                'body' => 'Hello {first_name}.',
            ],
        );
        $smsVersion = $this->templateVersion(
            key: 'sms.transactional.webinar.fixture_runner',
            channel: 'sms',
            payload: [
                'message' => 'Hello {first_name}.',
            ],
        );
        $chain = $this->chain(
            key: 'fixture.runner',
            steps: [
                [
                    'key' => 'immediate_wave',
                    'sort_order' => 10,
                    'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                    'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_SEND_ALL_ELIGIBLE,
                    'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                    'variants' => [
                        [
                            'key' => 'email',
                            'sort_order' => 10,
                            'message_template_version_id' => $emailVersion->getKey(),
                            'channel' => 'email',
                            'purpose' => 'transactional',
                            'scope' => 'webinar',
                            'message_type' => 'fixture_runner_email',
                            'queue' => 'confirmation_messages',
                        ],
                        [
                            'key' => 'sms',
                            'sort_order' => 20,
                            'message_template_version_id' => $smsVersion->getKey(),
                            'channel' => 'sms',
                            'purpose' => 'transactional',
                            'scope' => 'webinar',
                            'message_type' => 'fixture_runner_sms',
                            'queue' => 'confirmation_messages',
                        ],
                    ],
                ],
                [
                    'key' => 'delayed_email',
                    'sort_order' => 20,
                    'timing_type' => MessageChainStep::TIMING_DELAY,
                    'offset_seconds' => 3600,
                    'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
                    'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                    'variants' => [[
                        'key' => 'email',
                        'message_template_version_id' => $emailVersion->getKey(),
                        'channel' => 'email',
                        'purpose' => 'transactional',
                        'scope' => 'webinar',
                        'message_type' => 'fixture_runner_delayed',
                        'queue' => 'reminders',
                    ]],
                ],
            ],
        );

        $start = app(StartMessageChainEnrollmentAction::class);
        $enrollment = $start->handle(
            messageChain: $chain,
            recipient: $contact,
            context: $contact,
            origin: $contact,
            dedupeKey: 'fixture:runner:contact:'.$contact->getKey(),
        );
        $duplicate = $start->handle(
            messageChain: $chain,
            recipient: $contact,
            context: $contact,
            origin: $contact,
            dedupeKey: 'fixture:runner:contact:'.$contact->getKey(),
        );

        $this->assertSame($enrollment->getKey(), $duplicate->getKey());
        $this->assertSame(1, MessageChainEnrollment::query()->count());
        $this->assertSame(0, ScheduledMessage::query()->count());
        Queue::assertPushed(
            ProcessMessageChainEnrollmentJob::class,
            fn (ProcessMessageChainEnrollmentJob $job): bool =>
                $job->enrollmentId === $enrollment->getKey(),
        );

        app(ProcessMessageChainEnrollmentAction::class)->handle($enrollment);

        $messages = ScheduledMessage::query()
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $messages);
        $this->assertEquals(
            ['email', 'sms'],
            $messages->pluck('channel')->all(),
        );

        foreach ($messages as $message) {
            $this->assertSame(
                $enrollment->getKey(),
                $message->message_chain_enrollment_id,
            );
            $this->assertNotNull(
                $message->message_chain_step_variant_id,
            );
            $this->assertEquals([
                'to' => $message->channel === 'email'
                    ? $contact->email
                    : $contact->phone,
            ], $message->payload);
            $this->assertEquals([], $message->meta);
        }

        $this->markSent($messages->first());
        $this->assertSame(
            'immediate_wave',
            $enrollment->refresh()->currentMessageChainStep?->key,
        );
        $this->assertNull($enrollment->next_action_at);

        $this->markSkipped($messages->last());
        $enrollment->refresh();

        $this->assertSame(
            'delayed_email',
            $enrollment->currentMessageChainStep?->key,
        );
        $this->assertTrue(
            $enrollment->next_action_at?->equalTo(
                Carbon::now()->addHour(),
            ) ?? false,
        );
        $this->assertSame(2, ScheduledMessage::query()->count());

        Carbon::setTestNow(Carbon::now()->addHour());
        app(ProcessMessageChainEnrollmentAction::class)->handle($enrollment);

        $this->assertSame(3, ScheduledMessage::query()->count());

        $delayed = ScheduledMessage::query()
            ->where('message_type', 'fixture_runner_delayed')
            ->firstOrFail();

        $this->assertSame('email', $delayed->channel);
        $this->markSent($delayed);

        $enrollment->refresh();

        $this->assertSame(
            MessageChainEnrollment::STATUS_COMPLETED,
            $enrollment->status,
        );
        $this->assertNull($enrollment->current_message_chain_step_id);
        $this->assertNull($enrollment->next_action_at);
        $this->assertNotNull($enrollment->completed_at);
    }

    public function test_first_available_selects_the_first_currently_eligible_variant(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00 UTC');
        Queue::fake();

        $contact = $this->contactWithConsent(
            channels: ['sms'],
        );
        $emailVersion = $this->templateVersion(
            key: 'email.transactional.webinar.fixture_fallback',
            channel: 'email',
            payload: [
                'subject' => 'Fixture fallback',
                'body' => 'Email fallback.',
            ],
        );
        $smsVersion = $this->templateVersion(
            key: 'sms.transactional.webinar.fixture_fallback',
            channel: 'sms',
            payload: [
                'message' => 'SMS fallback.',
            ],
        );
        $chain = $this->chain(
            key: 'fixture.fallback',
            steps: [[
                'key' => 'fallback',
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
                'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                'variants' => [
                    [
                        'key' => 'email',
                        'sort_order' => 10,
                        'message_template_version_id' => $emailVersion->getKey(),
                        'channel' => 'email',
                        'purpose' => 'transactional',
                        'scope' => 'webinar',
                        'message_type' => 'fixture_fallback_email',
                    ],
                    [
                        'key' => 'sms',
                        'sort_order' => 20,
                        'message_template_version_id' => $smsVersion->getKey(),
                        'channel' => 'sms',
                        'purpose' => 'transactional',
                        'scope' => 'webinar',
                        'message_type' => 'fixture_fallback_sms',
                    ],
                ],
            ]],
        );

        $enrollment = app(StartMessageChainEnrollmentAction::class)->handle(
            messageChain: $chain,
            recipient: $contact,
            dedupeKey: 'fixture:fallback:'.$contact->getKey(),
        );

        app(ProcessMessageChainEnrollmentAction::class)->handle($enrollment);

        $message = ScheduledMessage::query()->sole();

        $this->assertSame('sms', $message->channel);
        $this->assertSame(
            'fixture_fallback_sms',
            $message->message_type,
        );

        $this->markSent($message);

        $this->assertSame(
            MessageChainEnrollment::STATUS_COMPLETED,
            $enrollment->refresh()->status,
        );
    }

    public function test_timing_resolution_uses_current_context_and_client_timezone(): void
    {
        config()->set('client.timezone', 'America/Chicago');

        $resolver = app(MessageChainTimingResolver::class);
        $anchored = new MessageChainStep([
            'timing_type' => MessageChainStep::TIMING_ANCHORED,
            'anchor_key' => 'fixture.starts_at',
            'offset_seconds' => -600,
        ]);
        $nextDay = new MessageChainStep([
            'timing_type' => MessageChainStep::TIMING_NEXT_DAY_AT,
            'anchor_key' => 'fixture.ends_at',
            'day_offset' => 1,
            'local_time' => '09:00:00',
        ]);
        $context = [
            'fixture' => [
                'starts_at' => '2026-08-01T15:00:00Z',
                'ends_at' => '2026-08-01T23:00:00Z',
            ],
        ];

        $this->assertTrue(
            $resolver->resolve(
                step: $anchored,
                context: $context,
                baseAt: Carbon::parse('2026-08-01T12:00:00Z'),
            )->equalTo(Carbon::parse('2026-08-01T14:50:00Z')),
        );
        $this->assertTrue(
            $resolver->resolve(
                step: $nextDay,
                context: $context,
                baseAt: Carbon::parse('2026-08-01T12:00:00Z'),
            )->equalTo(Carbon::parse('2026-08-02T14:00:00Z')),
        );
    }

    public function test_due_sweep_dispatches_one_processor_job_per_due_enrollment(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00 UTC');
        Queue::fake();

        $contact = $this->contactWithConsent(['email']);
        $version = $this->templateVersion(
            key: 'email.transactional.webinar.fixture_due',
            channel: 'email',
            payload: [
                'subject' => 'Fixture due',
                'body' => 'Fixture due.',
            ],
        );
        $chain = $this->chain(
            key: 'fixture.due',
            steps: [[
                'key' => 'due',
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'offset_seconds' => 60,
                'variants' => [[
                    'key' => 'email',
                    'message_template_version_id' => $version->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_due',
                ]],
            ]],
        );

        $enrollment = app(StartMessageChainEnrollmentAction::class)->handle(
            messageChain: $chain,
            recipient: $contact,
            dedupeKey: 'fixture:due:'.$contact->getKey(),
        );

        Queue::fake();
        Carbon::setTestNow(Carbon::now()->addMinute());

        (new ProcessDueMessageChainEnrollmentsJob())->handle();

        Queue::assertPushed(
            ProcessMessageChainEnrollmentJob::class,
            fn (ProcessMessageChainEnrollmentJob $job): bool =>
                $job->enrollmentId === $enrollment->getKey(),
        );
    }

    /**
     * @param array<int, string> $channels
     */
    private function contactWithConsent(
        array $channels,
    ): Contact {
        $contact = Contact::factory()->create([
            'first_name' => 'Fixture',
            'email' => 'fixture@example.test',
            'phone' => '+15555550123',
        ]);

        foreach ($channels as $channel) {
            MessageConsent::query()->create([
                'contact_id' => $contact->getKey(),
                'channel' => $channel,
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        return $contact;
    }

    private function templateVersion(
        string $key,
        string $channel,
        array $payload,
    ) {
        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => str($key)->headline()->toString(),
            'channel' => $channel,
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        return app(PublishMessageTemplateVersionAction::class)->handle(
            $template,
            $payload,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function chain(
        string $key,
        array $steps,
    ): MessageChain {
        $chain = MessageChain::query()->create([
            'key' => $key,
            'name' => str($key)->headline()->toString(),
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: $steps,
        );

        return $chain->refresh();
    }

    private function markSent(
        ScheduledMessage $message,
    ): void {
        $occurredAt = now()->toImmutable();

        $message->forceFill([
            'status' => ScheduledMessage::STATUS_SENT,
        ])->save();

        event(new ScheduledMessageSent(
            $message->fresh(),
            new ScheduledMessageTerminalResult(
                scheduledMessageId: (int) $message->getKey(),
                status: ScheduledMessage::STATUS_SENT,
                occurredAt: $occurredAt,
            ),
        ));
    }

    private function markSkipped(
        ScheduledMessage $message,
    ): void {
        $occurredAt = now()->toImmutable();

        $message->forceFill([
            'status' => ScheduledMessage::STATUS_SKIPPED,
        ])->save();

        event(new ScheduledMessageSkipped(
            $message->fresh(),
            new ScheduledMessageTerminalResult(
                scheduledMessageId: (int) $message->getKey(),
                status: ScheduledMessage::STATUS_SKIPPED,
                occurredAt: $occurredAt,
                reasonCode: 'test_step_skipped',
                reason: 'Test step skipped.',
            ),
        ));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
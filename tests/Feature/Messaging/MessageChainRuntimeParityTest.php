<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\CancelMessageChainEnrollmentAction;
use App\Modules\Messaging\Actions\ProcessMessageChainEnrollmentAction;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Actions\StartMessageChainEnrollmentAction;
use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageChainRuntimeParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_dependency_aware_reaches_a_fixed_point_for_same_pass_scheduled_dependencies(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');
        Queue::fake();

        $contact = $this->contactWithConsent(['email', 'sms']);
        [$emailVersion, $smsVersion] = $this->templateVersions();
        $chain = $this->chain('fixture.dependency.same_pass', [[
            'key' => 'follow_up',
            'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
            'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_DEPENDENCY_AWARE,
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'variants' => [
                [
                    'key' => 'sms',
                    'sort_order' => 10,
                    'message_template_version_id' => $smsVersion->getKey(),
                    'channel' => 'sms',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_dependency_sms',
                    'dependency_policy' => [
                        'requires_variant_states' => [
                            'email' => ['scheduled'],
                        ],
                    ],
                ],
                [
                    'key' => 'email',
                    'sort_order' => 20,
                    'message_template_version_id' => $emailVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_dependency_email',
                ],
            ],
        ]]);

        $enrollment = $this->start($chain, $contact, 'same-pass');

        app(ProcessMessageChainEnrollmentAction::class)->handle($enrollment);

        $messages = ScheduledMessage::query()
            ->where('message_chain_enrollment_id', $enrollment->getKey())
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $messages);
        $this->assertEqualsCanonicalizing(
            ['email', 'sms'],
            $messages->pluck('channel')->all(),
        );
        $this->assertSame(
            MessageChainEnrollment::STATUS_ACTIVE,
            $enrollment->refresh()->status,
        );
        $this->assertNull($enrollment->next_action_at);
    }

    public function test_dependency_aware_rechecks_after_terminal_delivery_and_unlocks_later_siblings(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');
        Queue::fake();

        $contact = $this->contactWithConsent(['email', 'sms']);
        [$emailVersion, $smsVersion] = $this->templateVersions();
        $chain = $this->chain('fixture.dependency.sent', [[
            'key' => 'follow_up',
            'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
            'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_DEPENDENCY_AWARE,
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'variants' => [
                [
                    'key' => 'email',
                    'sort_order' => 10,
                    'message_template_version_id' => $emailVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_dependency_email_sent',
                ],
                [
                    'key' => 'sms',
                    'sort_order' => 20,
                    'message_template_version_id' => $smsVersion->getKey(),
                    'channel' => 'sms',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_dependency_sms_after_sent',
                    'dependency_policy' => [
                        'requires_variant_states' => [
                            'email' => ['sent'],
                        ],
                    ],
                ],
            ],
        ]]);

        $enrollment = $this->start($chain, $contact, 'sent-unlock');
        $processor = app(ProcessMessageChainEnrollmentAction::class);
        $processor->handle($enrollment);

        $email = ScheduledMessage::query()
            ->where('message_chain_enrollment_id', $enrollment->getKey())
            ->where('channel', 'email')
            ->sole();

        $this->assertDatabaseMissing('scheduled_messages', [
            'message_chain_enrollment_id' => $enrollment->getKey(),
            'channel' => 'sms',
        ]);

        $email->forceFill(['status' => ScheduledMessage::STATUS_SENT])->save();
        $processor->handleTerminal($email->fresh());

        $sms = ScheduledMessage::query()
            ->where('message_chain_enrollment_id', $enrollment->getKey())
            ->where('channel', 'sms')
            ->sole();

        $this->assertSame(ScheduledMessage::STATUS_PENDING, $sms->status);
        $this->assertSame(
            MessageChainEnrollment::STATUS_ACTIVE,
            $enrollment->refresh()->status,
        );

        $sms->forceFill(['status' => ScheduledMessage::STATUS_SENT])->save();
        $processor->handleTerminal($sms->fresh());

        $this->assertSame(
            MessageChainEnrollment::STATUS_COMPLETED,
            $enrollment->refresh()->status,
        );
        $this->assertNull($enrollment->current_message_chain_step_id);
        $this->assertNull($enrollment->next_action_at);
    }

    public function test_dependency_aware_supports_skipped_failed_and_terminal_sibling_states(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');
        Queue::fake();

        foreach ([
            'skipped' => ScheduledMessage::STATUS_SKIPPED,
            'failed' => ScheduledMessage::STATUS_FAILED,
            'terminal' => ScheduledMessage::STATUS_FAILED,
        ] as $requiredState => $messageStatus) {
            $contact = $this->contactWithConsent(['email', 'sms'], $requiredState);
            [$emailVersion, $smsVersion] = $this->templateVersions($requiredState);
            $chain = $this->chain('fixture.dependency.'.$requiredState, [[
                'key' => 'follow_up',
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_DEPENDENCY_AWARE,
                'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                'variants' => [
                    [
                        'key' => 'email',
                        'sort_order' => 10,
                        'message_template_version_id' => $emailVersion->getKey(),
                        'channel' => 'email',
                        'purpose' => 'transactional',
                        'scope' => 'webinar',
                        'message_type' => 'fixture_dependency_email_'.$requiredState,
                    ],
                    [
                        'key' => 'sms',
                        'sort_order' => 20,
                        'message_template_version_id' => $smsVersion->getKey(),
                        'channel' => 'sms',
                        'purpose' => 'transactional',
                        'scope' => 'webinar',
                        'message_type' => 'fixture_dependency_sms_'.$requiredState,
                        'dependency_policy' => [
                            'requires_variant_states' => [
                                'email' => [$requiredState],
                            ],
                        ],
                    ],
                ],
            ]]);

            $enrollment = $this->start($chain, $contact, 'state-'.$requiredState);
            $processor = app(ProcessMessageChainEnrollmentAction::class);
            $processor->handle($enrollment);

            $email = ScheduledMessage::query()
                ->where('message_chain_enrollment_id', $enrollment->getKey())
                ->where('channel', 'email')
                ->sole();

            $email->forceFill(['status' => $messageStatus])->save();
            $processor->handleTerminal($email->fresh());

            $this->assertDatabaseHas('scheduled_messages', [
                'message_chain_enrollment_id' => $enrollment->getKey(),
                'channel' => 'sms',
                'status' => ScheduledMessage::STATUS_PENDING,
            ]);
        }
    }

    public function test_dependency_aware_can_treat_a_required_sibling_channel_as_explicitly_unavailable(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');
        Queue::fake();
        Config::set('messaging.channel_availability.sms.surfaces.campaigns', false);

        $contact = $this->contactWithConsent(['email']);
        [$emailVersion, $smsVersion] = $this->templateVersions('unavailable');
        $chain = $this->chain('fixture.dependency.unavailable', [[
            'key' => 'follow_up',
            'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
            'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_DEPENDENCY_AWARE,
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'variants' => [
                [
                    'key' => 'sms',
                    'sort_order' => 10,
                    'message_template_version_id' => $smsVersion->getKey(),
                    'channel' => 'sms',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_dependency_unavailable_sms',
                ],
                [
                    'key' => 'email',
                    'sort_order' => 20,
                    'message_template_version_id' => $emailVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_dependency_unavailable_email',
                    'dependency_policy' => [
                        'requires_variant_states' => [
                            'sms' => ['sent', 'unavailable'],
                        ],
                    ],
                ],
            ],
        ]]);

        $enrollment = $this->start(
            chain: $chain,
            contact: $contact,
            suffix: 'unavailable',
            surface: 'campaigns',
        );

        app(ProcessMessageChainEnrollmentAction::class)->handle($enrollment);

        $message = ScheduledMessage::query()
            ->where('message_chain_enrollment_id', $enrollment->getKey())
            ->sole();

        $this->assertSame('email', $message->channel);
    }

    public function test_dependency_aware_does_not_use_sibling_state_from_another_enrollment(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');
        Queue::fake();

        $contact = $this->contactWithConsent(['sms']);
        $otherContact = $this->contactWithConsent(['email'], 'other');
        [$emailVersion, $smsVersion] = $this->templateVersions('identity');
        $chain = $this->chain('fixture.dependency.identity', [[
            'key' => 'follow_up',
            'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
            'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_DEPENDENCY_AWARE,
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'variants' => [
                [
                    'key' => 'email',
                    'sort_order' => 10,
                    'message_template_version_id' => $emailVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_dependency_identity_email',
                ],
                [
                    'key' => 'sms',
                    'sort_order' => 20,
                    'message_template_version_id' => $smsVersion->getKey(),
                    'channel' => 'sms',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_dependency_identity_sms',
                    'dependency_policy' => [
                        'requires_variant_states' => [
                            'email' => ['scheduled'],
                        ],
                    ],
                ],
            ],
        ]]);

        $enrollment = $this->start($chain, $contact, 'identity-main');
        $otherEnrollment = $this->start($chain, $otherContact, 'identity-other');
        $step = $chain->currentVersion->steps()->with('variants')->firstOrFail();
        $emailVariant = $step->variants->firstWhere('key', 'email');

        ScheduledMessage::factory()
            ->forRecipient($otherContact)
            ->create([
                'message_chain_enrollment_id' => $otherEnrollment->getKey(),
                'message_chain_step_variant_id' => $emailVariant->getKey(),
                'channel' => 'email',
                'message_type' => 'fixture_dependency_identity_email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'status' => ScheduledMessage::STATUS_PENDING,
            ]);

        app(ProcessMessageChainEnrollmentAction::class)->handle($enrollment);

        $this->assertDatabaseMissing('scheduled_messages', [
            'message_chain_enrollment_id' => $enrollment->getKey(),
            'channel' => 'sms',
        ]);
    }

    public function test_cancellation_is_idempotent_and_skips_only_pending_chain_messages(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');
        Queue::fake();

        $contact = $this->contactWithConsent(['email']);
        [$emailVersion] = $this->templateVersions('cancel');
        $chain = $this->chain('fixture.cancel', [[
            'key' => 'message',
            'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
            'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'variants' => [[
                'key' => 'email',
                'message_template_version_id' => $emailVersion->getKey(),
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'message_type' => 'fixture_cancel_email',
            ]],
        ]]);

        $enrollment = $this->start($chain, $contact, 'cancel');
        app(ProcessMessageChainEnrollmentAction::class)->handle($enrollment);
        $pending = ScheduledMessage::query()
            ->where('message_chain_enrollment_id', $enrollment->getKey())
            ->sole();

        $sending = ScheduledMessage::factory()->forRecipient($contact)->sending()->create([
            'message_chain_enrollment_id' => $enrollment->getKey(),
        ]);
        $sent = ScheduledMessage::factory()->forRecipient($contact)->sent()->create([
            'message_chain_enrollment_id' => $enrollment->getKey(),
        ]);
        $failed = ScheduledMessage::factory()->forRecipient($contact)->failed()->create([
            'message_chain_enrollment_id' => $enrollment->getKey(),
        ]);
        $skipped = ScheduledMessage::factory()->forRecipient($contact)->skipped()->create([
            'message_chain_enrollment_id' => $enrollment->getKey(),
        ]);
        $outboxCountBefore = ScheduledMessageOutboxEvent::query()->count();

        $cancel = app(CancelMessageChainEnrollmentAction::class);
        $cancelled = $cancel->handle($enrollment, 'campaign_deactivated');

        $this->assertSame(MessageChainEnrollment::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame('campaign_deactivated', $cancelled->exit_reason_code);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertNull($cancelled->current_message_chain_step_id);
        $this->assertNull($cancelled->next_action_at);
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $pending->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_SENDING, $sending->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_SENT, $sent->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_FAILED, $failed->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $skipped->refresh()->status);
        $this->assertSame(
            $outboxCountBefore + 1,
            ScheduledMessageOutboxEvent::query()->count(),
        );

        $cancel->handle($cancelled, 'different_reason');

        $this->assertSame(
            $outboxCountBefore + 1,
            ScheduledMessageOutboxEvent::query()->count(),
        );
        $this->assertSame(
            'campaign_deactivated',
            $cancelled->refresh()->exit_reason_code,
        );
    }

    public function test_cancellation_does_not_rewrite_terminal_chain_enrollments(): void
    {
        Queue::fake();

        $contact = $this->contactWithConsent(['email']);
        [$emailVersion] = $this->templateVersions('terminal-cancel');
        $chain = $this->chain('fixture.cancel.terminal', [[
            'key' => 'message',
            'variants' => [[
                'key' => 'email',
                'message_template_version_id' => $emailVersion->getKey(),
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'message_type' => 'fixture_terminal_cancel_email',
            ]],
        ]]);
        $enrollment = $this->start($chain, $contact, 'terminal-cancel');
        $completedAt = now()->subMinute();
        $enrollment->forceFill([
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'completed_at' => now()->subMinute(),
        ])->save();

        $completedAt = $enrollment->fresh()->completed_at;

        $result = app(CancelMessageChainEnrollmentAction::class)->handle(
            $enrollment,
            'should_not_replace_terminal_state',
        );

        $this->assertSame(MessageChainEnrollment::STATUS_COMPLETED, $result->status);
        $this->assertTrue($result->completed_at?->equalTo($completedAt) ?? false);
        $this->assertNull($result->cancelled_at);
        $this->assertNull($result->exit_reason_code);
    }

    public function test_chain_progression_jobs_are_marked_for_after_commit_dispatch(): void
    {
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');
        Queue::fake();

        $contact = $this->contactWithConsent(['email']);
        [$emailVersion] = $this->templateVersions('after-commit');
        $chain = $this->chain('fixture.after_commit', [
            [
                'key' => 'first',
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'variants' => [[
                    'key' => 'email',
                    'message_template_version_id' => $emailVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_after_commit_first',
                ]],
            ],
            [
                'key' => 'second',
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'offset_seconds' => 60,
                'variants' => [[
                    'key' => 'email',
                    'message_template_version_id' => $emailVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'webinar',
                    'message_type' => 'fixture_after_commit_second',
                ]],
            ],
        ]);

        $enrollment = $this->start($chain, $contact, 'after-commit');

        Queue::assertPushed(
            ProcessMessageChainEnrollmentJob::class,
            fn (ProcessMessageChainEnrollmentJob $job): bool =>
                $job->enrollmentId === $enrollment->getKey()
                && $job->afterCommit === true,
        );

        $processor = app(ProcessMessageChainEnrollmentAction::class);
        $processor->handle($enrollment);
        $first = ScheduledMessage::query()
            ->where('message_chain_enrollment_id', $enrollment->getKey())
            ->sole();
        $first->forceFill(['status' => ScheduledMessage::STATUS_SENT])->save();
        $processor->handleTerminal($first->fresh());

        Queue::assertPushed(
            ProcessMessageChainEnrollmentJob::class,
            2,
        );
        Queue::assertPushed(
            ProcessMessageChainEnrollmentJob::class,
            fn (ProcessMessageChainEnrollmentJob $job): bool =>
                $job->enrollmentId === $enrollment->getKey()
                && $job->afterCommit === true,
        );
    }

    /**
     * @param array<int, string> $channels
     */
    private function contactWithConsent(
        array $channels,
        string $suffix = 'default',
    ): Contact {
        $contact = Contact::factory()->create([
            'first_name' => 'Fixture',
            'email' => 'fixture-'.$suffix.'-'.str()->uuid().'@example.test',
            'phone' => '+1555'.random_int(1000000, 9999999),
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

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function templateVersions(string $suffix = 'default'): array
    {
        return [
            $this->templateVersion(
                key: 'email.transactional.webinar.fixture_'.$suffix.'_'.str()->uuid(),
                channel: 'email',
                payload: [
                    'subject' => 'Fixture',
                    'body' => 'Hello {first_name}.',
                ],
            ),
            $this->templateVersion(
                key: 'sms.transactional.webinar.fixture_'.$suffix.'_'.str()->uuid(),
                channel: 'sms',
                payload: [
                    'message' => 'Hello {first_name}.',
                ],
            ),
        ];
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
    private function chain(string $key, array $steps): MessageChain
    {
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

    private function start(
        MessageChain $chain,
        Contact $contact,
        string $suffix,
        ?string $surface = null,
    ): MessageChainEnrollment {
        return app(StartMessageChainEnrollmentAction::class)->handle(
            messageChain: $chain,
            recipient: $contact,
            dedupeKey: 'fixture:message-chain-runtime:'.$suffix.':'.$contact->getKey(),
            surface: $surface,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
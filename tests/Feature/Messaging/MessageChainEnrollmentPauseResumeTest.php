<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\PauseMessageChainEnrollmentAction;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Actions\ResumeMessageChainEnrollmentAction;
use App\Modules\Messaging\Actions\StartMessageChainEnrollmentAction;
use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageChainEnrollmentPauseResumeTest extends TestCase
{
    use RefreshDatabase;

    public function test_resume_preserves_remaining_delay_for_an_unmaterialized_future_step(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');

        [$chain, $contact] = $this->chainAndContact('future-delay');
        $enrollment = app(StartMessageChainEnrollmentAction::class)->handle(
            messageChain: $chain,
            recipient: $contact,
            dedupeKey: 'pause-resume:future-delay',
            surface: 'campaigns',
        );

        $this->assertTrue(
            $enrollment->next_action_at?->equalTo(Carbon::parse('2026-08-18 14:00:00 UTC')) ?? false,
        );

        Carbon::setTestNow('2026-08-18 12:30:00 UTC');
        $paused = app(PauseMessageChainEnrollmentAction::class)->handle($enrollment);

        $this->assertSame(MessageChainEnrollment::STATUS_PAUSED, $paused->status);
        $this->assertTrue(
            $paused->next_action_at?->equalTo(Carbon::parse('2026-08-18 14:00:00 UTC')) ?? false,
        );

        Carbon::setTestNow('2026-08-18 13:30:00 UTC');
        $resumed = app(ResumeMessageChainEnrollmentAction::class)->handle($paused);

        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $resumed->status);
        $this->assertTrue(
            $resumed->next_action_at?->equalTo(Carbon::parse('2026-08-18 15:00:00 UTC')) ?? false,
        );
        $this->assertTrue(
            $resumed->resumed_at?->equalTo(Carbon::parse('2026-08-18 13:30:00 UTC')) ?? false,
        );

        Queue::assertPushed(ProcessMessageChainEnrollmentJob::class, 2);
        Queue::assertPushed(
            ProcessMessageChainEnrollmentJob::class,
            fn (ProcessMessageChainEnrollmentJob $job): bool =>
                $job->enrollmentId === $resumed->getKey()
                && $job->afterCommit === true,
        );
    }

    public function test_pause_skips_pending_chain_messages_but_does_not_rewrite_sending_work(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');

        [$chain, $contact] = $this->chainAndContact('pending-wave');
        $enrollment = app(StartMessageChainEnrollmentAction::class)->handle(
            messageChain: $chain,
            recipient: $contact,
            dedupeKey: 'pause-resume:pending-wave',
            surface: 'campaigns',
        );

        $enrollment->forceFill([
            'next_action_at' => null,
        ])->save();

        $pending = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'message_chain_enrollment_id' => $enrollment->getKey(),
            ]);
        $sending = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->sending()
            ->create([
                'message_chain_enrollment_id' => $enrollment->getKey(),
            ]);

        $paused = app(PauseMessageChainEnrollmentAction::class)->handle(
            enrollment: $enrollment,
            reason: 'human_reply',
        );

        $this->assertSame(MessageChainEnrollment::STATUS_PAUSED, $paused->status);
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $pending->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_SENDING, $sending->refresh()->status);

        Carbon::setTestNow('2026-08-18 12:15:00 UTC');
        $resumed = app(ResumeMessageChainEnrollmentAction::class)->handle($paused);

        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $resumed->status);
        $this->assertTrue(
            $resumed->next_action_at?->equalTo(Carbon::parse('2026-08-18 12:15:00 UTC')) ?? false,
        );

        Queue::assertPushed(ProcessMessageChainEnrollmentJob::class, 2);
        Queue::assertPushed(
            ProcessMessageChainEnrollmentJob::class,
            fn (ProcessMessageChainEnrollmentJob $job): bool =>
                $job->enrollmentId === $resumed->getKey()
                && $job->afterCommit === true,
        );
    }

    /**
     * @return array{0: MessageChain, 1: Contact}
     */
    private function chainAndContact(string $suffix): array
    {
        $template = MessageTemplate::query()->create([
            'key' => 'email.transactional.campaigns.pause_resume_'.$suffix,
            'name' => 'Pause Resume '.$suffix,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        $templateVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            $template,
            [
                'subject' => 'Fixture',
                'body' => 'Fixture body.',
            ],
        );

        $chain = MessageChain::query()->create([
            'key' => 'fixture.pause_resume.'.$suffix,
            'name' => 'Pause Resume '.$suffix,
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [[
                'key' => 'step_1',
                'name' => 'Step 1',
                'sort_order' => 10,
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'offset_seconds' => 7200,
                'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
                'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                'conditions' => [],
                'is_active' => true,
                'variants' => [[
                    'key' => 'email',
                    'sort_order' => 10,
                    'message_template_version_id' => $templateVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'transactional',
                    'scope' => 'campaigns',
                    'message_type' => 'pause_resume_'.$suffix,
                    'queue' => 'marketing',
                    'dependency_policy' => [],
                    'conditions' => [],
                    'is_active' => true,
                ]],
            ]],
        );

        return [
            $chain->refresh(),
            Contact::factory()->create([
                'email' => 'pause-resume-'.$suffix.'@example.test',
            ]),
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ActivateCampaignAction;
use App\Modules\Campaigns\Actions\CancelCampaignEnrollmentAction;
use App\Modules\Campaigns\Actions\DeactivateCampaignAction;
use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Actions\PauseCampaignEnrollmentAction;
use App\Modules\Campaigns\Actions\ResumeCampaignEnrollmentAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class CampaignEnrollmentLifecycleBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_campaign_cancellation_delegates_to_message_chain_cancellation(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');

        [$campaign, $chain, $contact, $enrollment] = $this->campaignEnrollment('cancel');
        $chainEnrollment = $enrollment->messageChainEnrollment;

        $pending = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            ]);
        $sending = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->sending()
            ->create([
                'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            ]);

        $cancelled = app(CancelCampaignEnrollmentAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
            reason: 'high_intent_reply',
            meta: ['source' => 'test'],
        );

        $this->assertInstanceOf(CampaignEnrollment::class, $cancelled);
        $this->assertSame(MessageChainEnrollment::STATUS_CANCELLED, $cancelled->runtimeStatus());
        $this->assertSame('high_intent_reply', data_get($cancelled->meta, 'lifecycle.last_cancellation.reason'));

        $chainEnrollment->refresh();
        $this->assertSame(MessageChainEnrollment::STATUS_CANCELLED, $chainEnrollment->status);
        $this->assertSame('high_intent_reply', $chainEnrollment->exit_reason_code);
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $pending->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_SENDING, $sending->refresh()->status);
        $this->assertSame(MessageChain::STATUS_ACTIVE, $chain->refresh()->status);
    }

    public function test_campaign_pause_and_resume_project_message_chain_lifecycle_state(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');

        [$campaign, , $contact, $enrollment] = $this->campaignEnrollment('pause-resume');
        $chainEnrollment = $enrollment->messageChainEnrollment;
        $chainEnrollment->forceFill(['next_action_at' => null])->save();

        $pending = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'message_chain_enrollment_id' => $chainEnrollment->getKey(),
            ]);

        $paused = app(PauseCampaignEnrollmentAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
            reason: 'human_reply',
        );

        $this->assertInstanceOf(CampaignEnrollment::class, $paused);
        $this->assertSame(MessageChainEnrollment::STATUS_PAUSED, $paused->runtimeStatus());
        $this->assertSame(MessageChainEnrollment::STATUS_PAUSED, $paused->messageChainEnrollment->status);
        $this->assertSame('human_reply', data_get($paused->meta, 'lifecycle.last_pause.reason'));
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $pending->refresh()->status);

        Carbon::setTestNow('2026-08-18 12:15:00 UTC');

        $resumed = app(ResumeCampaignEnrollmentAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
            reason: 'human_follow_up_complete',
        );

        $this->assertInstanceOf(CampaignEnrollment::class, $resumed);
        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $resumed->runtimeStatus());
        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $resumed->messageChainEnrollment->status);
        $this->assertSame(
            'human_follow_up_complete',
            data_get($resumed->meta, 'lifecycle.last_resume.reason'),
        );
        $this->assertTrue(
            $resumed->messageChainEnrollment->next_action_at?->equalTo(
                Carbon::parse('2026-08-18 12:15:00 UTC'),
            ) ?? false,
        );

        Queue::assertPushed(ProcessMessageChainEnrollmentJob::class, 2);
        Queue::assertPushed(
            ProcessMessageChainEnrollmentJob::class,
            fn (ProcessMessageChainEnrollmentJob $job): bool =>
                $job->enrollmentId === $resumed->message_chain_enrollment_id
                && $job->afterCommit === true,
        );
    }

    public function test_deactivation_cancels_linked_enrollments_and_reactivation_does_not_resurrect_them(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-08-18 12:00:00 UTC');

        [$campaign, $chain, $firstContact, $firstEnrollment] = $this->campaignEnrollment('deactivate');
        $secondContact = Contact::factory()->create([
            'email' => 'deactivate-second@example.test',
        ]);
        $secondEnrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $secondContact,
            campaignKey: $campaign->key,
        );

        $firstPending = ScheduledMessage::factory()
            ->forRecipient($firstContact)
            ->create([
                'message_chain_enrollment_id' => $firstEnrollment->message_chain_enrollment_id,
            ]);
        $secondPending = ScheduledMessage::factory()
            ->forRecipient($secondContact)
            ->create([
                'message_chain_enrollment_id' => $secondEnrollment->message_chain_enrollment_id,
            ]);

        $result = app(DeactivateCampaignAction::class)->handle(
            campaign: $campaign,
            source: 'test',
        );

        $this->assertTrue($result['status_changed']);
        $this->assertSame(2, $result['enrollments_cancelled']);
        $this->assertSame(2, $result['scheduled_messages_skipped']);
        $this->assertSame(Campaign::STATUS_INACTIVE, $campaign->refresh()->status);
        $this->assertSame(MessageChain::STATUS_INACTIVE, $chain->refresh()->status);

        foreach ([$firstEnrollment, $secondEnrollment] as $enrollment) {
            $enrollment->refresh();
            $this->assertSame(MessageChainEnrollment::STATUS_CANCELLED, $enrollment->runtimeStatus());
            $this->assertSame(
                MessageChainEnrollment::STATUS_CANCELLED,
                $enrollment->messageChainEnrollment()->firstOrFail()->status,
            );
            $this->assertSame(
                DeactivateCampaignAction::REASON,
                data_get($enrollment->meta, 'lifecycle.last_cancellation.reason'),
            );
        }

        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $firstPending->refresh()->status);
        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $secondPending->refresh()->status);

        app(ActivateCampaignAction::class)->handle(
            campaign: $campaign,
            source: 'test',
        );

        $this->assertSame(Campaign::STATUS_ACTIVE, $campaign->refresh()->status);
        $this->assertSame(MessageChain::STATUS_ACTIVE, $chain->refresh()->status);
        $this->assertSame(MessageChainEnrollment::STATUS_CANCELLED, $firstEnrollment->refresh()->runtimeStatus());
        $this->assertSame(MessageChainEnrollment::STATUS_CANCELLED, $secondEnrollment->refresh()->runtimeStatus());

        $newContact = Contact::factory()->create([
            'email' => 'deactivate-new@example.test',
        ]);
        $newEnrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $newContact,
            campaignKey: $campaign->key,
        );

        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $newEnrollment->runtimeStatus());
        $this->assertSame(MessageChainEnrollment::STATUS_ACTIVE, $newEnrollment->messageChainEnrollment->status);
    }

    public function test_archived_campaign_cannot_be_reactivated(): void
    {
        [$campaign, $chain] = $this->campaignWithChain(
            suffix: 'archived-campaign',
            campaignStatus: Campaign::STATUS_ARCHIVED,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archived Campaign');

        try {
            app(ActivateCampaignAction::class)->handle($campaign, source: 'test');
        } finally {
            $this->assertSame(Campaign::STATUS_ARCHIVED, $campaign->refresh()->status);
            $this->assertSame(MessageChain::STATUS_ACTIVE, $chain->refresh()->status);
        }
    }

    public function test_activation_requires_a_selected_published_message_chain(): void
    {
        $campaign = Campaign::query()->create([
            'key' => 'unbound',
            'name' => 'Unbound Campaign',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign_test',
            'status' => Campaign::STATUS_INACTIVE,
            'meta' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('without a selected MessageChain');

        app(ActivateCampaignAction::class)->handle($campaign, source: 'test');
    }

    public function test_activation_rejects_an_archived_selected_message_chain(): void
    {
        Queue::fake();

        [$campaign, $chain] = $this->campaignWithChain(
            suffix: 'archived-chain',
            campaignStatus: Campaign::STATUS_INACTIVE,
            chainStatus: MessageChain::STATUS_ARCHIVED,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot activate archived MessageChain');

        try {
            app(ActivateCampaignAction::class)->handle($campaign, source: 'test');
        } finally {
            $this->assertSame(Campaign::STATUS_INACTIVE, $campaign->refresh()->status);
            $this->assertSame(MessageChain::STATUS_ARCHIVED, $chain->refresh()->status);
        }
    }

    /**
     * @return array{0: Campaign, 1: MessageChain, 2: Contact, 3: CampaignEnrollment}
     */
    private function campaignEnrollment(string $suffix): array
    {
        [$campaign, $chain] = $this->campaignWithChain($suffix);
        $contact = Contact::factory()->create([
            'email' => $suffix.'@example.test',
        ]);
        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        return [$campaign, $chain, $contact, $enrollment];
    }

    /**
     * @return array{0: Campaign, 1: MessageChain}
     */
    private function campaignWithChain(
        string $suffix,
        string $campaignStatus = Campaign::STATUS_ACTIVE,
        string $chainStatus = MessageChain::STATUS_ACTIVE,
    ): array {
        $template = MessageTemplate::query()->create([
            'key' => 'email.marketing.campaign_test.'.$suffix,
            'name' => 'Campaign '.$suffix,
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
            'key' => 'campaign.'.$suffix,
            'name' => 'Campaign '.$suffix.' chain',
            'status' => $chainStatus === MessageChain::STATUS_ARCHIVED
                ? MessageChain::STATUS_ACTIVE
                : $chainStatus,
            'source' => 'campaign_preset_bridge',
            'is_customized' => false,
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
                    'purpose' => 'marketing',
                    'scope' => 'campaign_test',
                    'message_type' => $suffix.'_step_1',
                    'queue' => 'marketing',
                    'dependency_policy' => [],
                    'conditions' => [],
                    'is_active' => true,
                ]],
            ]],
        );

        if ($chainStatus === MessageChain::STATUS_ARCHIVED) {
            $chain->forceFill(['status' => MessageChain::STATUS_ARCHIVED])->save();
        }

        $campaign = Campaign::query()->create([
            'key' => $suffix,
            'name' => 'Campaign '.$suffix,
            'message_chain_id' => $chain->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign_test',
            'status' => $campaignStatus,
            'meta' => [],
        ]);

        return [$campaign, $chain->refresh()];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
<?php

namespace Tests\Feature\Campaigns;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\CampaignSimulationService;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Jobs\ProcessDueMessageChainEnrollmentsJob;
use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\DevMessageSink;
use App\Modules\Messaging\Services\ScheduledMessageEventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class CampaignSimulatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulator_route_is_hard_blocked_after_environment_changes_to_production(): void
    {
        $this->assertTrue(Route::has('crm.campaigns.simulator.index'));

        config()->set('modules.enabled', ['campaigns', 'messaging']);
        $user = User::factory()->create();

        $this->app->detectEnvironment(fn (): string => 'production');
        config()->set('app.env', 'production');

        $this->actingAs($user)
            ->get(route('crm.campaigns.simulator.index'))
            ->assertNotFound();
    }

    public function test_start_uses_real_campaign_enrollment_but_isolates_the_chain_from_normal_due_scanning(): void
    {
        [$campaign, $contact] = $this->campaignAndContact('start');
        $service = app(CampaignSimulationService::class);
        $outerNow = Carbon::parse('2026-08-18 20:00:00 UTC');
        Carbon::setTestNow($outerNow);
        $queueRoot = Queue::getFacadeRoot();

        $simulation = $service->start(
            campaign: $campaign,
            contact: $contact,
            fakeNow: '2026-08-18 09:00:00',
        );

        $snapshot = $service->snapshot($simulation);

        $chainEnrollment = $simulation->messageChainEnrollment;

        $this->assertSame(
            $chainEnrollment->getKey(),
            $snapshot['chain']['enrollment_id'],
        );

        $this->assertNotEmpty($snapshot['steps']);

        $this->assertInstanceOf(MessageChainEnrollment::class, $chainEnrollment);
        $this->assertSame(CampaignSimulationService::MESSAGE_CHAIN_SURFACE, $chainEnrollment->surface);
        $this->assertSame(CampaignSimulationService::TOOL_KEY, data_get($simulation->meta, 'testing_tool.key'));
        $this->assertNotNull(data_get($simulation->meta, 'testing_tool.run_id'));
        $this->assertTrue(Carbon::now()->equalTo($outerNow));
        $this->assertSame($queueRoot, Queue::getFacadeRoot());

        Carbon::setTestNow('2026-08-19 20:00:00 UTC');
        Queue::fake();

        (new ProcessDueMessageChainEnrollmentsJob())->handle();

        Queue::assertNotPushed(
            ProcessMessageChainEnrollmentJob::class,
            fn (ProcessMessageChainEnrollmentJob $job): bool =>
                $job->enrollmentId === $chainEnrollment->getKey(),
        );
    }

    public function test_local_processing_uses_dev_sink_real_terminal_runtime_and_reset(): void
    {
        [$campaign, $contact] = $this->campaignAndContact('process');
        $service = app(CampaignSimulationService::class);
        $simulation = $service->start(
            campaign: $campaign,
            contact: $contact,
            fakeNow: '2026-08-18 10:00:00',
        );

        $sink = Mockery::mock(DevMessageSink::class);
        $sink->shouldReceive('store')
            ->once()
            ->with('email', Mockery::type('array'));
        $this->app->instance(DevMessageSink::class, $sink);

        $previousQueueConnection = config('queue.default');

        $this->app->detectEnvironment(fn (): string => 'local');
        config()->set('app.env', 'local');
        config()->set('queue.default', 'redis');

        try {
            $processed = $service->process($simulation);
        } finally {
            $this->app->detectEnvironment(fn (): string => 'testing');
            config()->set('app.env', 'testing');
            config()->set('queue.default', $previousQueueConnection);
        }

        $message = ScheduledMessage::query()
            ->where('message_chain_enrollment_id', $processed->message_chain_enrollment_id)
            ->firstOrFail();
        $terminal = ScheduledMessageTerminalResult::fromScheduledMessage($message);

        $this->assertSame(ScheduledMessage::STATUS_SENT, $message->status);
        $this->assertSame('dev_sink', $terminal->provider);
        $this->assertSame(
            MessageChainEnrollment::STATUS_COMPLETED,
            $processed->messageChainEnrollment->fresh()->status,
        );

        $outbox = $message->terminalOutboxEvent()->firstOrFail();
        $outbox->forceFill([
            'status' => 'pending',
            'available_at' => now()->subMinute(),
            'claim_token' => null,
            'claim_expires_at' => null,
            'published_at' => null,
        ])->save();

        $this->assertSame(0, app(ScheduledMessageEventOutbox::class)->publishPending());
        $this->assertFalse(app(ScheduledMessageEventOutbox::class)->publish((int) $outbox->getKey()));
        $this->assertSame('pending', $outbox->fresh()->status);

        $campaignEnrollmentId = (int) $processed->getKey();
        $chainEnrollmentId = (int) $processed->message_chain_enrollment_id;
        $scheduledMessageId = (int) $message->getKey();

        $service->reset($processed);

        $this->assertDatabaseMissing('campaign_enrollments', ['id' => $campaignEnrollmentId]);
        $this->assertDatabaseMissing('message_chain_enrollments', ['id' => $chainEnrollmentId]);
        $this->assertDatabaseMissing('scheduled_messages', ['id' => $scheduledMessageId]);
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->getKey()]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->getKey()]);
    }

    /**
     * @return array{0: Campaign, 1: Contact}
     */
    private function campaignAndContact(string $suffix): array
    {
        $template = MessageTemplate::query()->create([
            'key' => 'email.transactional.campaigns.simulator_'.$suffix,
            'name' => 'Campaign Simulator '.$suffix,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        $templateVersion = app(PublishMessageTemplateVersionAction::class)->handle(
            $template,
            [
                'subject' => 'Simulator fixture',
                'body' => 'Hello {first_name}.',
            ],
        );

        $chain = MessageChain::query()->create([
            'key' => 'campaign.simulator.'.$suffix,
            'name' => 'Campaign Simulator '.$suffix,
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [[
                'key' => 'step_1',
                'name' => 'Step 1',
                'sort_order' => 10,
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'offset_seconds' => 0,
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
                    'message_type' => 'simulator_'.$suffix,
                    'queue' => 'marketing',
                    'dependency_policy' => [],
                    'conditions' => [],
                    'is_active' => true,
                ]],
            ]],
        );

        $campaign = Campaign::factory()->create([
            'key' => 'simulator_'.$suffix,
            'name' => 'Simulator '.$suffix,
            'message_chain_id' => $chain->getKey(),
            'status' => Campaign::STATUS_ACTIVE,
            'purpose' => 'transactional',
            'scope' => 'campaigns',
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Simulation',
            'email' => 'simulator-'.$suffix.'@example.test',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'campaigns',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        return [$campaign->refresh(), $contact];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->app->detectEnvironment(fn (): string => 'testing');
        config()->set('app.env', 'testing');

        parent::tearDown();
    }
}
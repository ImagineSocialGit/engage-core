<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ClaimScheduledMessageForSendingAction;
use App\Modules\Messaging\Actions\RecoverStaleScheduledMessageClaimsAction;
use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Jobs\RecoverStaleScheduledMessageClaimsJob;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageConfigValidator;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryPolicy;
use App\Modules\Messaging\Validation\MessagingSetupValidationContributor;
use App\Support\Queues\QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class QueueContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.env', 'local');
        Config::set('queue.default', 'redis');
        Config::set('queue.connections.redis.queue', 'default');
        Config::set('horizon.defaults.supervisor-1.connection', 'redis');
        Config::set(
            'horizon.environments.local.supervisor-1.queue',
            QueueContract::QUEUES,
        );
        Config::set('messaging.delivery.pending_message_overdue_grace_seconds', 300);
        Config::set('messaging.delivery.claim_lease_seconds', 300);

        Carbon::setTestNow('2026-07-22 18:00:00');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_executable_inventory_keeps_active_queues_and_rejects_obsolete_names(): void
    {
        $contract = app(QueueContract::class);

        $this->assertContains('emails', QueueContract::QUEUES);
        $this->assertContains('marketing', QueueContract::QUEUES);
        $this->assertNotContains('campaigns', QueueContract::QUEUES);
        $this->assertNotContains('waitlist', QueueContract::QUEUES);
        $this->assertSame([], $contract->validationIssues());
    }

    public function test_message_definition_validation_rejects_an_unregistered_queue(): void
    {
        $issues = app(MessageConfigValidator::class)->validateDefinitionArray(
            definition: [
                'dispatch_key' => 'broadcast_send',
                'payload_class' => EmailPayload::class,
                'queue' => 'campaigns',
                'payload' => [
                    'subject' => 'Subject',
                    'body' => 'Body',
                ],
            ],
            path: 'messaging.email.definitions.marketing.broadcast.notice',
            channel: 'email',
            purpose: 'marketing',
            scope: 'broadcast',
            surface: 'broadcasts',
        );

        $this->assertTrue(collect($issues)->contains(
            fn (array $issue): bool => ($issue['path'] ?? null)
                === 'messaging.email.definitions.marketing.broadcast.notice.queue'
                && str_contains(
                    (string) ($issue['message'] ?? ''),
                    'not registered in the executable queue contract',
                ),
        ));
    }

    public function test_scheduling_rejects_an_invalid_queue_before_persistence(): void
    {
        $contact = Contact::factory()->create();

        try {
            app(ScheduleMessageAction::class)->handle(
                recipient: $contact,
                channel: 'email',
                purpose: 'marketing',
                scope: 'campaign',
                messageType: 'queue_contract_test',
                payloadClass: EmailPayload::class,
                payload: [
                    'to' => $contact->email,
                    'subject' => 'Subject',
                    'body' => 'Body',
                ],
                meta: [
                    'queue' => 'campaigns',
                ],
            );

            $this->fail('Expected invalid queue scheduling to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'not registered in the executable queue contract',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('scheduled_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_scheduling_rejects_a_registered_queue_not_consumed_by_horizon(): void
    {
        Config::set(
            'horizon.environments.local.supervisor-1.queue',
            ['default'],
        );

        $contact = Contact::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Queue [marketing] is registered but is not consumed by Horizon',
        );

        app(ScheduleMessageAction::class)->handle(
            recipient: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'campaign',
            messageType: 'queue_contract_test',
            payloadClass: EmailPayload::class,
            payload: [
                'to' => $contact->email,
                'subject' => 'Subject',
                'body' => 'Body',
            ],
            meta: [
                'queue' => 'marketing',
            ],
        );
    }

    public function test_setup_validation_reports_horizon_queue_drift(): void
    {
        Config::set(
            'horizon.environments.local.supervisor-1.queue',
            array_values(array_diff(QueueContract::QUEUES, ['marketing'])),
        );

        $findings = collect(
            app(MessagingSetupValidationContributor::class)->findings(),
        );

        $this->assertTrue($findings->contains(
            fn ($finding): bool => $finding->code
                === 'messaging.queue_contract.registered_queue_unconsumed'
                && ($finding->context['queue'] ?? null) === 'marketing',
        ));
    }

    public function test_setup_validation_reports_a_stored_pending_queue_mismatch(): void
    {
        ScheduledMessage::factory()->email()->create([
            'queue' => 'campaigns',
            'status' => ScheduledMessage::STATUS_PENDING,
            'send_at' => now()->addHour(),
        ]);

        $findings = collect(
            app(MessagingSetupValidationContributor::class)->findings(),
        );

        $this->assertTrue($findings->contains(
            fn ($finding): bool => $finding->code
                === 'messaging.pending_message_queue_unregistered'
                && ($finding->context['queue'] ?? null) === 'campaigns'
                && ($finding->context['pending_count'] ?? null) === 1,
        ));
    }

    public function test_setup_validation_reports_reference_registry_drift(): void
    {
        $referenceQueues = Config::get('reference.keys.queues');
        unset($referenceQueues['emails']);
        $referenceQueues['waitlist'] = [
            'description' => 'Obsolete queue.',
            'status' => 'active',
        ];
        Config::set('reference.keys.queues', $referenceQueues);

        $codes = collect(app(QueueContract::class)->validationIssues())
            ->pluck('code')
            ->all();

        $this->assertContains('reference_queue_missing', $codes);
        $this->assertContains('reference_queue_unregistered', $codes);
    }

    public function test_recovery_pass_logs_unsupported_and_overdue_pending_messages(): void
    {
        ScheduledMessage::factory()->email()->create([
            'queue' => 'campaigns',
            'status' => ScheduledMessage::STATUS_PENDING,
            'send_at' => now()->addHour(),
        ]);

        $overdue = ScheduledMessage::factory()->email()->create([
            'queue' => 'emails',
            'status' => ScheduledMessage::STATUS_PENDING,
            'send_at' => now()->subMinutes(6),
        ]);

        Log::spy();

        (new RecoverStaleScheduledMessageClaimsJob())->handle(
            recoverStaleClaims: app(RecoverStaleScheduledMessageClaimsAction::class),
            deliveryPolicy: app(ScheduledMessageDeliveryPolicy::class),
            queueContract: app(QueueContract::class),
        );

        Log::shouldHaveReceived('critical')
            ->once()
            ->with(
                'Messaging queue audit detected a queue contract violation.',
                \Mockery::on(
                    fn (array $context): bool => ($context['unsupported_pending_queues']['campaigns'] ?? null) === 1
                        && ($context['overdue_pending_count'] ?? null) === 1
                        && in_array(
                            $overdue->getKey(),
                            $context['overdue_pending_ids'] ?? [],
                            true,
                        ),
                ),
            );
    }

    public function test_recovery_does_not_redispatch_onto_an_invalid_stored_queue(): void
    {
        $message = ScheduledMessage::factory()->email()->create([
            'queue' => 'campaigns',
        ]);

        app(ClaimScheduledMessageForSendingAction::class)->handle($message);
        Carbon::setTestNow(now()->addMinutes(6));

        Log::spy();

        (new RecoverStaleScheduledMessageClaimsJob())->handle(
            recoverStaleClaims: app(RecoverStaleScheduledMessageClaimsAction::class),
            deliveryPolicy: app(ScheduledMessageDeliveryPolicy::class),
            queueContract: app(QueueContract::class),
        );

        Queue::assertNotPushed(
            SendScheduledMessageJob::class,
            fn (SendScheduledMessageJob $job): bool => $job->scheduledMessageId
                === $message->getKey(),
        );

        Log::shouldHaveReceived('critical')
            ->once()
            ->with(
                'Recovered ScheduledMessage was blocked from invalid queue redispatch.',
                \Mockery::on(
                    fn (array $context): bool => ($context['scheduled_message_id'] ?? null)
                        === $message->getKey()
                        && ($context['queue'] ?? null) === 'campaigns',
                ),
            );
    }
}
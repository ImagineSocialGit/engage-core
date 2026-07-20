<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchConsentOptInMessageAction;
use App\Modules\Messaging\Data\Consent\MessageConsentGrantResult;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Webinars\Actions\AddRegistrantToWebinarProviderAction;
use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Actions\FinalizeWebinarRegistrationAction;
use App\Modules\Webinars\Actions\QueueWebinarRegistrationFinalizationAction;
use App\Modules\Webinars\Actions\SyncWebinarRegistrationToProviderAction;
use App\Modules\Webinars\Data\ProviderRegistrationData;
use App\Modules\Webinars\Data\WebinarProviderSyncResult;
use App\Modules\Webinars\Data\WebinarRegistrationConsentTransition;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Jobs\RecoverWebinarRegistrationFinalizationsJob;
use App\Modules\Webinars\Jobs\SyncWebinarRegistrationToProviderJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebinarRegistrationProviderSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => ['webinar_registrations' => true],
            'purpose_scopes' => ['transactional:webinar' => true],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => false,
            'requires_explicit_opt_in' => true,
            'surfaces' => ['webinar_registrations' => false],
            'purpose_scopes' => [],
        ]);

        Config::set('webinars.registration.finalization.queue_failure_retry_seconds', 1);
        Config::set('webinars.registration.finalization.queue_stale_after_seconds', 300);
        Config::set('webinars.registration.finalization.processing_stale_after_seconds', 600);
        Config::set('webinars.registration.finalization.provider_claim_stale_after_seconds', 600);
    }

    public function test_registration_stages_finalization_and_consent_transitions_before_queueing(): void
    {
        Queue::fake();

        $webinar = Webinar::factory()->create([
            'external_id' => null,
        ]);

        $result = app(CreateWebinarRegistrationAction::class)->handle(
            validated: [
                'first_name' => 'Jeff',
                'email' => 'jeff@example.com',
                'transactional_email_consent' => true,
            ],
            request: Request::create('/register', 'POST', server: [
                'REMOTE_ADDR' => '127.0.0.1',
                'HTTP_USER_AGENT' => 'PHPUnit',
            ]),
            webinar: $webinar,
        );

        $registration = $result->registration->fresh();
        $state = data_get(
            $registration?->meta,
            WebinarRegistrationFinalizationResult::META_KEY,
        );

        $this->assertTrue($result->wasCreated());
        $this->assertSame('queued', $state['status']);
        $this->assertSame('initial_registration', $state['mode']);
        $this->assertSame(1, $state['queue_dispatch_attempts']);
        $this->assertCount(1, $state['consent_transitions']);
        $this->assertSame(
            MessageConsent::query()->value('id'),
            $state['consent_transitions'][0]['consent_id'],
        );

        Queue::assertPushed(
            SyncWebinarRegistrationToProviderJob::class,
            fn (SyncWebinarRegistrationToProviderJob $job): bool =>
                $job->registrationId === $registration?->getKey(),
        );
    }

    public function test_queue_dispatch_failure_leaves_recoverable_pending_state(): void
    {
        $registration = $this->registrationWithFinalization();

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Queue unavailable'));
        app()->instance(Dispatcher::class, $dispatcher);

        $result = app(QueueWebinarRegistrationFinalizationAction::class)
            ->handle($registration);

        $registration->refresh();

        $this->assertSame(
            WebinarRegistrationFinalizationResult::STATUS_PENDING,
            $result->status,
        );
        $this->assertSame(
            'pending',
            data_get($registration->meta, 'registration_finalization.status'),
        );
        $this->assertSame(
            'queue_dispatch_failed',
            data_get($registration->meta, 'registration_finalization.failure_reason'),
        );
        $this->assertNotNull(
            data_get($registration->meta, 'registration_finalization.next_retry_at'),
        );
    }

    public function test_recovery_job_requeues_pending_finalization_once(): void
    {
        Queue::fake();

        $registration = $this->registrationWithFinalization([
            'next_retry_at' => now()->subMinute()->toISOString(),
        ]);

        $job = new RecoverWebinarRegistrationFinalizationsJob();
        $queueFinalization = app(QueueWebinarRegistrationFinalizationAction::class);

        $job->handle($queueFinalization);
        $job->handle($queueFinalization);

        Queue::assertPushed(SyncWebinarRegistrationToProviderJob::class, 1);

        $registration->refresh();
        $this->assertSame(
            'queued',
            data_get($registration->meta, 'registration_finalization.status'),
        );
    }

    public function test_persisted_consent_transition_is_rehydrated_during_finalization(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'registrant@example.test',
        ]);
        $consent = MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'consented_at' => now(),
            'source' => 'webinar_registration',
            'meta' => [],
        ]);
        $grant = new MessageConsentGrantResult(
            consent: $consent,
            channel: 'email',
            purpose: 'transactional',
            requestedScope: 'webinar',
            domain: 'webinar',
            wasActive: false,
            isActive: true,
            created: true,
            becameActive: true,
        );
        $registration = $this->registrationWithFinalization([
            'consent_transitions' => [
                WebinarRegistrationConsentTransition::fromGrant($grant)->toArray(),
            ],
        ], contact: $contact);

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')->never();
        app()->instance(AddRegistrantToWebinarProviderAction::class, $provider);

        $messages = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $messages->shouldReceive('handle')
            ->once()
            ->withArgs(fn (
                WebinarRegistration $passedRegistration,
                ?array $contextKeys,
                array $consentGrants,
            ): bool => $passedRegistration->is($registration)
                && $contextKeys === null
                && count($consentGrants) === 1
                && $consentGrants[0]->consent->is($consent))
            ->andReturn([]);
        app()->instance(DispatchWebinarRegistrationMessagesAction::class, $messages);

        $standalone = Mockery::mock(DispatchConsentOptInMessageAction::class);
        $standalone->shouldReceive('handle')->never();
        app()->instance(DispatchConsentOptInMessageAction::class, $standalone);

        (new SyncWebinarRegistrationToProviderJob(
            (int) $registration->getKey(),
        ))->handle(app(FinalizeWebinarRegistrationAction::class));

        $registration->refresh();

        $this->assertSame(
            'completed',
            data_get($registration->meta, 'registration_finalization.status'),
        );
        $this->assertSame(
            'registration_messages_planned',
            data_get($registration->meta, 'registration_finalization.completion_reason'),
        );
    }

    public function test_connection_loss_requires_reconciliation_and_blocks_confirmation_planning(): void
    {
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
        ]);
        $registration = $this->registrationWithFinalization(
            webinar: $webinar,
        );

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')
            ->once()
            ->andThrow(new ConnectionException('Connection lost after submission.'));
        app()->instance(AddRegistrantToWebinarProviderAction::class, $provider);

        $messages = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $messages->shouldReceive('handle')->never();
        app()->instance(DispatchWebinarRegistrationMessagesAction::class, $messages);

        $result = app(FinalizeWebinarRegistrationAction::class)
            ->handle($registration);

        $registration->refresh();

        $this->assertTrue($result?->requiresReconciliation());
        $this->assertSame(
            'reconciliation_required',
            data_get($registration->meta, 'provider_sync.status'),
        );
        $this->assertSame(
            'reconciliation_required',
            data_get($registration->meta, 'registration_finalization.status'),
        );
    }

    public function test_rate_limit_response_is_a_safe_retryable_provider_failure(): void
    {
        $registration = $this->providerBackedRegistration();

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')
            ->once()
            ->andThrow($this->requestException(429));

        $result = (new SyncWebinarRegistrationToProviderAction($provider))
            ->handle($registration);

        $registration->refresh();

        $this->assertSame(
            WebinarProviderSyncResult::STATUS_RETRYABLE_FAILURE,
            $result->status,
        );
        $this->assertTrue($result->shouldRetry());
        $this->assertSame(
            'retryable_failure',
            data_get($registration->meta, 'provider_sync.status'),
        );
    }

    public function test_validation_rejection_is_a_permanent_provider_failure(): void
    {
        $registration = $this->providerBackedRegistration();

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')
            ->once()
            ->andThrow($this->requestException(422));

        $result = (new SyncWebinarRegistrationToProviderAction($provider))
            ->handle($registration);

        $registration->refresh();

        $this->assertSame(
            WebinarProviderSyncResult::STATUS_PERMANENT_FAILURE,
            $result->status,
        );
        $this->assertTrue($result->permanentlyFailed());
        $this->assertSame(
            'permanent_failure',
            data_get($registration->meta, 'provider_sync.status'),
        );
    }

    public function test_successful_provider_sync_is_application_idempotent(): void
    {
        $registration = $this->providerBackedRegistration([
            'provider_sync' => [
                'status' => 'succeeded',
                'provider' => 'zoom',
            ],
        ]);

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')->never();

        $result = (new SyncWebinarRegistrationToProviderAction($provider))
            ->handle($registration);

        $this->assertSame(
            WebinarProviderSyncResult::STATUS_ALREADY_SUCCEEDED,
            $result->status,
        );
    }

    public function test_successful_provider_sync_records_remote_data_and_completes_finalization(): void
    {
        $registration = $this->registrationWithFinalization(
            webinar: Webinar::factory()->create([
                'platform' => 'zoom',
                'external_id' => 'provider-webinar-123',
            ]),
        );

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')
            ->once()
            ->andReturn(new ProviderRegistrationData(
                provider: 'zoom',
                registrantId: 'registrant-123',
                joinUrl: 'https://zoom.example.test/join',
                raw: ['id' => 'registrant-123'],
            ));
        app()->instance(AddRegistrantToWebinarProviderAction::class, $provider);

        $messages = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $messages->shouldReceive('handle')->once()->andReturn([]);
        app()->instance(DispatchWebinarRegistrationMessagesAction::class, $messages);

        $result = app(FinalizeWebinarRegistrationAction::class)
            ->handle($registration);

        $registration->refresh();

        $this->assertTrue($result?->complete());
        $this->assertSame(
            'succeeded',
            data_get($registration->meta, 'provider_sync.status'),
        );
        $this->assertSame(
            'registrant-123',
            data_get($registration->meta, 'provider.registrant_id'),
        );
        $this->assertSame(
            'completed',
            data_get($registration->meta, 'registration_finalization.status'),
        );
    }

    public function test_retry_exhaustion_is_persisted_as_terminal_finalization_failure(): void
    {
        $registration = $this->registrationWithFinalization();
        $job = new SyncWebinarRegistrationToProviderJob(
            (int) $registration->getKey(),
        );

        $job->failed(new RuntimeException('Final attempt failed.'));

        $registration->refresh();

        $this->assertSame(
            'failed',
            data_get($registration->meta, 'registration_finalization.status'),
        );
        $this->assertSame(
            'retry_exhausted',
            data_get($registration->meta, 'registration_finalization.failure_reason'),
        );
        $this->assertSame(
            RuntimeException::class,
            data_get($registration->meta, 'registration_finalization.last_error_class'),
        );
    }

    /**
     * @param array<string, mixed> $stateOverrides
     */
    private function registrationWithFinalization(
        array $stateOverrides = [],
        ?Webinar $webinar = null,
        ?Contact $contact = null,
    ): WebinarRegistration {
        $webinar ??= Webinar::factory()->create([
            'external_id' => null,
        ]);
        $contact ??= Contact::factory()->create();

        return WebinarRegistration::factory()
            ->for($webinar)
            ->for($contact)
            ->create([
                'meta' => [
                    WebinarRegistrationFinalizationResult::META_KEY => array_replace([
                        'status' => 'pending',
                        'mode' => 'initial_registration',
                        'consent_transitions' => [],
                        'attempts' => 0,
                        'queue_dispatch_attempts' => 0,
                        'staged_at' => now()->toISOString(),
                    ], $stateOverrides),
                ],
            ]);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function providerBackedRegistration(
        array $meta = [],
    ): WebinarRegistration {
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
        ]);

        return WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'meta' => $meta,
            ]);
    }

    private function requestException(int $status): RequestException
    {
        return new RequestException(
            new Response(new Psr7Response($status)),
        );
    }
}
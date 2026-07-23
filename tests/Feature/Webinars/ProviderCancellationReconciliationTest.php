<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Actions\CancelWebinarRegistrationAction;
use App\Modules\Webinars\Actions\CancelWebinarRegistrationWithProviderAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\WebinarProviderCancellationResult;
use App\Modules\Webinars\Jobs\CancelWebinarRegistrationWithProviderJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarRegistrationCancellationPolicy;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProviderCancellationReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cancellation_policy_classifies_coherent_registration_states(): void
    {
        $policy = app(WebinarRegistrationCancellationPolicy::class);

        foreach (['pending', 'registered'] as $status) {
            $this->assertSame(
                WebinarRegistrationCancellationPolicy::STATE_CANCELLABLE,
                $policy->stateFor(new WebinarRegistration([
                    'status' => $status,
                    'cancelled_at' => null,
                ])),
            );
        }

        $this->assertSame(
            WebinarRegistrationCancellationPolicy::STATE_ALREADY_CANCELLED,
            $policy->stateFor(new WebinarRegistration([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ])),
        );

        foreach (['attended', 'missed'] as $status) {
            $this->assertSame(
                WebinarRegistrationCancellationPolicy::STATE_INELIGIBLE,
                $policy->stateFor(new WebinarRegistration([
                    'status' => $status,
                    'cancelled_at' => null,
                ])),
            );
        }
    }

    public function test_local_cancellation_queues_provider_work_once_without_calling_the_provider_inline(): void
    {
        Queue::fake();

        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'status' => 'registered',
                'cancelled_at' => null,
                'meta' => [
                    'provider' => [
                        'name' => 'zoom',
                        'registrant_id' => 'provider-registrant-123',
                    ],
                ],
            ]);

        $first = app(CancelWebinarRegistrationAction::class)->handle($registration);
        $second = app(CancelWebinarRegistrationAction::class)->handle($first);

        $this->assertSame('cancelled', $second->status);
        $this->assertNotNull($second->cancelled_at);
        $this->assertSame('pending', data_get($second->meta, 'provider_cancellation.status'));
        $this->assertSame(1, data_get($second->meta, 'provider_cancellation.queue_attempts'));

        Queue::assertPushed(
            CancelWebinarRegistrationWithProviderJob::class,
            fn (CancelWebinarRegistrationWithProviderJob $job): bool =>
                $job->registrationId === $registration->getKey(),
        );
        Queue::assertPushed(CancelWebinarRegistrationWithProviderJob::class, 1);
    }

    public function test_coherent_existing_cancellation_is_a_complete_no_op(): void
    {
        Queue::fake();

        $registration = WebinarRegistration::factory()->create([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'meta' => [
                'integrity_marker' => 'preserved',
            ],
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $cancelledAt = $registration->cancelled_at?->toISOString();
        $outboxCount = DB::table('automation_event_outbox_events')->count();

        $result = app(CancelWebinarRegistrationAction::class)
            ->handle($registration);

        $scheduledMessage->refresh();

        $this->assertSame('cancelled', $result->status);
        $this->assertSame($cancelledAt, $result->cancelled_at?->toISOString());
        $this->assertSame(
            ['integrity_marker' => 'preserved'],
            $result->meta,
        );
        $this->assertSame(
            ScheduledMessage::STATUS_PENDING,
            $scheduledMessage->status,
        );
        $this->assertSame(
            $outboxCount,
            DB::table('automation_event_outbox_events')->count(),
        );

        Queue::assertNothingPushed();
    }

    public function test_terminal_and_inconsistent_registration_states_reject_cancellation_without_side_effects(): void
    {
        Queue::fake();

        $cases = [
            [
                'status' => 'attended',
                'cancelled_at' => null,
            ],
            [
                'status' => 'missed',
                'cancelled_at' => null,
            ],
            [
                'status' => 'registered',
                'cancelled_at' => now(),
            ],
            [
                'status' => 'cancelled',
                'cancelled_at' => null,
            ],
        ];

        foreach ($cases as $index => $case) {
            $registration = WebinarRegistration::factory()->create([
                ...$case,
                'meta' => [
                    'integrity_marker' => 'case-'.$index,
                ],
            ]);

            $scheduledMessage = ScheduledMessage::factory()->create([
                'context_type' => $registration->getMorphClass(),
                'context_id' => $registration->getKey(),
                'status' => ScheduledMessage::STATUS_PENDING,
            ]);

            $outboxCount = DB::table(
                'automation_event_outbox_events',
            )->count();
            $expectedCancelledAt = $registration->cancelled_at?->toISOString();

            try {
                app(CancelWebinarRegistrationAction::class)
                    ->handle($registration);

                $this->fail(
                    'Terminal and inconsistent registrations must reject cancellation.',
                );
            } catch (LogicException $exception) {
                $this->assertSame(
                    'Webinar registration cancellation is not permitted for its current state.',
                    $exception->getMessage(),
                );
            }

            $registration->refresh();
            $scheduledMessage->refresh();

            $this->assertSame($case['status'], $registration->status);
            $this->assertSame(
                $expectedCancelledAt,
                $registration->cancelled_at?->toISOString(),
            );
            $this->assertSame(
                ['integrity_marker' => 'case-'.$index],
                $registration->meta,
            );
            $this->assertSame(
                ScheduledMessage::STATUS_PENDING,
                $scheduledMessage->status,
            );
            $this->assertSame(
                $outboxCount,
                DB::table('automation_event_outbox_events')->count(),
            );
        }

        Queue::assertNothingPushed();
    }

    public function test_provider_failure_is_durable_and_a_later_retry_is_idempotent(): void
    {
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'meta' => [
                    'provider' => [
                        'data' => [
                            'registrant_id' => 'provider-registrant-123',
                        ],
                    ],
                ],
            ]);

        $provider = Mockery::mock(WebinarProvider::class);
        $provider->shouldReceive('cancelRegistration')
            ->once()
            ->ordered()
            ->andThrow(new RuntimeException('Temporary provider outage.'));
        $provider->shouldReceive('cancelRegistration')
            ->once()
            ->ordered()
            ->andReturnNull();

        $manager = Mockery::mock(WebinarProviderManager::class);
        $manager->shouldReceive('forWebinar')
            ->twice()
            ->with(Mockery::on(fn (Webinar $resolved): bool => $resolved->is($webinar)))
            ->andReturn($provider);

        $action = new CancelWebinarRegistrationWithProviderAction($manager);
        $job = new CancelWebinarRegistrationWithProviderJob(
            (int) $registration->getKey(),
        );

        try {
            $job->handle($action);
            $this->fail('The provider failure should remain queue-retryable.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Webinar registration provider cancellation is not complete.',
                $exception->getMessage(),
            );
        }

        $failed = $registration->fresh();

        $this->assertSame('failed', data_get($failed?->meta, 'provider_cancellation.status'));
        $this->assertSame('provider_request', data_get($failed?->meta, 'provider_cancellation.failure_stage'));
        $this->assertSame(RuntimeException::class, data_get($failed?->meta, 'provider_cancellation.last_error_class'));
        $this->assertSame(1, data_get($failed?->meta, 'provider_cancellation.attempts'));

        $retry = $action->handle($failed);

        $this->assertSame(WebinarProviderCancellationResult::STATUS_SUCCEEDED, $retry->status);
        $this->assertSame('succeeded', data_get($registration->fresh()?->meta, 'provider_cancellation.status'));
        $this->assertSame(2, data_get($registration->fresh()?->meta, 'provider_cancellation.attempts'));

        $job->handle($action);

        $this->assertSame(2, data_get($registration->fresh()?->meta, 'provider_cancellation.attempts'));
    }

    public function test_missing_provider_registration_identity_remains_retryable(): void
    {
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'meta' => [],
            ]);

        $manager = Mockery::mock(WebinarProviderManager::class);
        $manager->shouldReceive('forWebinar')->never();

        $result = (new CancelWebinarRegistrationWithProviderAction($manager))
            ->handle($registration);

        $this->assertSame(WebinarProviderCancellationResult::STATUS_FAILED, $result->status);
        $this->assertTrue($result->shouldRetry());
        $this->assertSame(
            'provider_registration_missing',
            data_get($registration->fresh()?->meta, 'provider_cancellation.failure_stage'),
        );
    }

    public function test_crm_surfaces_failed_provider_cancellations_and_can_requeue_them(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'Example Registrant',
            'email' => 'registrant@example.test',
        ]);
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
            'ends_at' => now()->subHour(),
        ]);
        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($contact)
            ->create([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'meta' => [
                    'provider' => [
                        'registrant_id' => 'provider-registrant-123',
                    ],
                    'provider_cancellation' => [
                        'status' => 'failed',
                        'provider' => 'zoom',
                        'attempts' => 5,
                        'failure_stage' => 'provider_request',
                    ],
                ],
            ]);

        $indexUrl = route('crm.webinar-series.index', ['archived' => 1]);

        $this->actingAs($user)
            ->get($indexUrl)
            ->assertOk()
            ->assertSee($webinar->title)
            ->assertSee(
                route(
                    'crm.webinar-registrations.provider-cancellation.retry',
                    $registration,
                ),
                false,
            )
            ->assertSee('bg-red-50', false);

        $this->actingAs($user)
            ->from($indexUrl)
            ->post(route(
                'crm.webinar-registrations.provider-cancellation.retry',
                $registration,
            ))
            ->assertRedirect($indexUrl)
            ->assertSessionHas('success');

        $this->assertSame(
            'pending',
            data_get($registration->fresh()?->meta, 'provider_cancellation.status'),
        );

        Queue::assertPushed(
            CancelWebinarRegistrationWithProviderJob::class,
            fn (CancelWebinarRegistrationWithProviderJob $job): bool =>
                $job->registrationId === $registration->getKey(),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
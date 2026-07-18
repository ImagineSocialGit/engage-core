<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\CancelWebinarRegistrationAction;
use App\Modules\Webinars\Actions\CancelWebinarRegistrationWithProviderAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\WebinarProviderCancellationResult;
use App\Modules\Webinars\Jobs\CancelWebinarRegistrationWithProviderJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProviderCancellationReconciliationTest extends TestCase
{
    use RefreshDatabase;

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
        $manager->shouldReceive('provider')
            ->twice()
            ->with('zoom')
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
        $manager->shouldReceive('provider')->never();

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
            ->assertSee('1 provider cancellation failure')
            ->assertSee('Retry provider cancellation');

        $this->actingAs($user)
            ->from($indexUrl)
            ->post(route(
                'crm.webinar-registrations.provider-cancellation.retry',
                $registration,
            ))
            ->assertRedirect($indexUrl)
            ->assertSessionHas(
                'success',
                'The provider cancellation retry has been queued.',
            );

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

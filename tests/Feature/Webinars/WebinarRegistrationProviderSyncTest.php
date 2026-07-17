<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Webinars\Actions\AddRegistrantToWebinarProviderAction;
use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Actions\SyncWebinarRegistrationToProviderAction;
use App\Modules\Webinars\Data\WebinarProviderSyncResult;
use App\Modules\Webinars\Jobs\SyncWebinarRegistrationToProviderJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_provider_failure_does_not_roll_back_the_local_registration(): void
    {
        Queue::fake();

        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
        ]);

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('Temporary provider outage'));
        app()->instance(AddRegistrantToWebinarProviderAction::class, $provider);

        $messages = Mockery::mock(DispatchWebinarRegistrationMessagesAction::class);
        $messages->shouldReceive('handle')->never();
        app()->instance(DispatchWebinarRegistrationMessagesAction::class, $messages);

        $automation = Mockery::mock(EmitWebinarAutomationEventAction::class);
        $automation->shouldReceive('forRegistration')->once();
        app()->instance(EmitWebinarAutomationEventAction::class, $automation);

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
            webinarSlug: $webinar,
        );

        $this->assertTrue($result->wasCreated());
        $this->assertDatabaseHas('webinar_registrations', [
            'id' => $result->registration->getKey(),
            'webinar_id' => $webinar->getKey(),
        ]);

        $registration = $result->registration->fresh();

        $this->assertSame('failed', data_get($registration?->meta, 'provider_sync.status'));

        Queue::assertPushed(
            SyncWebinarRegistrationToProviderJob::class,
            fn (SyncWebinarRegistrationToProviderJob $job): bool =>
                $job->registrationId === $result->registration->getKey(),
        );
    }

    public function test_successful_provider_sync_is_application_idempotent(): void
    {
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'meta' => [
                    'provider_sync' => [
                        'status' => 'succeeded',
                        'provider' => 'zoom',
                    ],
                ],
            ]);

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')->never();

        $action = new SyncWebinarRegistrationToProviderAction($provider);
        $result = $action->handle($registration);

        $this->assertSame(
            WebinarProviderSyncResult::STATUS_ALREADY_SUCCEEDED,
            $result->status,
        );
    }
}

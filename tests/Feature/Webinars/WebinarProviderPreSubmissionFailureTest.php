<?php

namespace Tests\Feature\Webinars;

use App\Integrations\Webinars\Zoom\Mappers\ZoomAttendanceMapper;
use App\Integrations\Webinars\Zoom\ZoomEventService;
use App\Integrations\Webinars\Zoom\ZoomOAuthService;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\AddRegistrantToWebinarProviderAction;
use App\Modules\Webinars\Actions\SyncWebinarRegistrationToProviderAction;
use App\Modules\Webinars\Data\WebinarProviderSyncResult;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Exceptions\ProviderRegistrationPreparationConnectionException;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Mockery;
use Tests\TestCase;

class WebinarProviderPreSubmissionFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_zoom_authentication_connection_failure_is_marked_as_pre_submission(): void
    {
        $auth = Mockery::mock(ZoomOAuthService::class);
        $auth->shouldReceive('getAccessToken')
            ->once()
            ->andThrow(new ConnectionException('Zoom OAuth connection timed out.'));

        $service = new ZoomEventService(
            auth: $auth,
            attendanceMapper: app(ZoomAttendanceMapper::class),
        );

        $this->expectException(
            ProviderRegistrationPreparationConnectionException::class,
        );

        $service->registerAttendee(
            eventType: WebinarProviderEventType::Meeting,
            eventId: 'meeting-123',
            data: [
                'email' => 'registrant@example.test',
                'first_name' => 'Example',
                'last_name' => 'Registrant',
            ],
        );
    }

    public function test_pre_submission_connection_failure_is_safe_to_retry(): void
    {
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-event-123',
        ]);
        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->for(Contact::factory())
            ->create();

        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')
            ->once()
            ->andThrow(new ProviderRegistrationPreparationConnectionException(
                'Provider authentication could not be reached.',
            ));

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
        $this->assertSame(
            'provider_pre_submission_connection_failed',
            data_get($registration->meta, 'provider_sync.failure_reason'),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
<?php

namespace Tests\Feature\Webinars;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\AddRegistrantToWebinarProviderAction;
use App\Modules\Webinars\Actions\RetryWebinarRegistrationFinalizationAction;
use App\Modules\Webinars\Actions\SyncWebinarRegistrationToProviderAction;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\Dashboard\WebinarActivityDashboardPanelProvider;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class WebinarRegistrationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => ['webinar_registrations' => true],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);
        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => ['webinar_registrations' => false],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);
        Config::set('webinars.register.content.registration.consents', [
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => true, 'sms' => true],
        ]);
    }

    public function test_dashboard_summary_does_not_count_recent_successes_as_registration_failures(): void
    {
        $user = User::factory()->create();
        $webinar = Webinar::factory()->create([
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        WebinarRegistration::factory()
            ->count(15)
            ->for($webinar)
            ->create([
                'registered_at' => now(),
                'meta' => [
                    'registration_finalization' => ['status' => 'completed'],
                    'provider_sync' => ['status' => 'succeeded', 'provider' => 'zoom'],
                ],
            ]);

        WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'registered_at' => now(),
                'meta' => [
                    'registration_finalization' => [
                        'status' => 'failed',
                        'failure_reason' => 'provider_rejected_registration',
                    ],
                    'provider_sync' => [
                        'status' => 'permanent_failure',
                        'provider' => 'zoom',
                        'failure_reason' => 'provider_rejected_registration',
                        'provider_error_code' => '3027',
                        'provider_error_message' => 'Host can not register',
                    ],
                ],
            ]);

        $request = Request::create('/', 'GET');
        $request->setUserResolver(fn (): User => $user);

        $panel = app(WebinarActivityDashboardPanelProvider::class)->panel($request);

        $this->assertSame('webinar registration failures', $panel['summary_label']);
        $this->assertSame(1, $panel['count']);
        $this->assertSame(1, $panel['attention_count']);
    }

    public function test_zoom_host_is_blocked_before_a_local_registration_is_created(): void
    {
        Config::set('services.zoom.account_id', 'account-id');
        Config::set('services.zoom.client_id', 'client-id');
        Config::set('services.zoom.client_secret', 'client-secret');

        $series = WebinarSeries::factory()->meeting()->create([
            'status' => 'active',
            'slug' => 'host-block-test',
        ]);
        $webinar = Webinar::factory()->meeting()->create([
            'webinar_series_id' => $series->getKey(),
            'external_id' => 'meeting-123',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
        ]);

        Http::fake([
            'https://zoom.us/oauth/token' => Http::response([
                'access_token' => 'token',
            ]),
            'https://api.zoom.us/v2/meetings/meeting-123' => Http::response([
                'id' => 'meeting-123',
                'host_email' => 'owner@example.test',
            ]),
        ]);

        $response = $this
            ->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), [
                'company_website' => '',
                'registration_form_ready' => 'ready',
                'registration_form_interacted' => 'human',
                'first_name' => 'Zoom',
                'last_name' => 'Owner',
                'email' => 'OWNER@example.test',
                'phone' => null,
                'transactional_email_consent' => true,
                'transactional_sms_consent' => false,
                'marketing_email_consent' => false,
                'marketing_sms_consent' => false,
            ]);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('contacts', [
            'email' => 'owner@example.test',
        ]);
        $this->assertDatabaseCount('webinar_registrations', 0);

        Http::assertSent(fn ($request): bool =>
            $request->method() === 'GET'
            && $request->url() === 'https://api.zoom.us/v2/meetings/meeting-123'
        );
        Http::assertNotSent(fn ($request): bool =>
            $request->method() === 'POST'
            && str_contains($request->url(), '/meetings/meeting-123/registrants')
        );
    }

    public function test_zoom_host_rejection_preserves_provider_code_and_message(): void
    {
        $registration = $this->rejectedRegistrationCandidate();
        $provider = Mockery::mock(AddRegistrantToWebinarProviderAction::class);
        $provider->shouldReceive('handle')
            ->once()
            ->andThrow(new RequestException(
                new Response(new Psr7Response(
                    400,
                    ['Content-Type' => 'application/json'],
                    json_encode([
                        'code' => 3027,
                        'message' => 'Host can not register',
                    ], JSON_THROW_ON_ERROR),
                )),
            ));

        $result = (new SyncWebinarRegistrationToProviderAction($provider))
            ->handle($registration);

        $registration->refresh();

        $this->assertTrue($result->permanentlyFailed());
        $this->assertSame('provider_rejected_registration', $result->reason);
        $this->assertSame(
            '3027',
            data_get($registration->meta, 'provider_sync.provider_error_code'),
        );
        $this->assertSame(
            'Host can not register',
            data_get($registration->meta, 'provider_sync.provider_error_message'),
        );
        $this->assertSame(
            'Host can not register (Zoom 3027)',
            $registration->registrationRecoveryFailureMessage(),
        );
    }

    public function test_operator_can_remove_a_confirmed_provider_rejection_without_deleting_history(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'Zoom Owner',
            'email' => 'owner@example.test',
        ]);
        $registration = WebinarRegistration::factory()
            ->for($contact)
            ->create([
                'status' => 'registered',
                'meta' => [
                    'registration_finalization' => [
                        'status' => 'failed',
                        'failure_reason' => 'provider_rejected_registration',
                        'failed_at' => now()->toISOString(),
                    ],
                    'provider_sync' => [
                        'status' => 'permanent_failure',
                        'provider' => 'zoom',
                        'failure_reason' => 'provider_rejected_registration',
                        'provider_error_code' => '3027',
                        'provider_error_message' => 'Host can not register',
                    ],
                ],
            ]);
        $returnTo = route('crm.webinar-series.index', ['attention' => 1]);

        $this->actingAs($user)
            ->from($returnTo)
            ->post(route(
                'crm.webinar-registrations.finalization.remove',
                $registration,
            ))
            ->assertRedirect($returnTo)
            ->assertSessionHas('success');

        $registration->refresh();

        $this->assertSame('cancelled', $registration->status);
        $this->assertNotNull($registration->cancelled_at);
        $this->assertSame(
            'completed',
            data_get($registration->meta, 'registration_finalization.status'),
        );
        $this->assertSame(
            'operator_removed_registration',
            data_get($registration->meta, 'registration_finalization.completion_reason'),
        );
        $this->assertSame(
            'remove_registration',
            data_get($registration->meta, 'registration_recovery.decision'),
        );
        $this->assertSame(
            $user->getKey(),
            data_get($registration->meta, 'registration_recovery.removed_by'),
        );
        $this->assertSame(
            '3027',
            data_get($registration->meta, 'provider_sync.provider_error_code'),
        );
        $this->assertDatabaseHas('contacts', [
            'id' => $contact->getKey(),
            'email' => 'owner@example.test',
        ]);
    }

    public function test_operator_retry_clears_stale_provider_error_details_before_resubmission(): void
    {
        Queue::fake();

        $registration = WebinarRegistration::factory()->create([
            'meta' => [
                'registration_finalization' => [
                    'status' => 'failed',
                    'failure_reason' => 'provider_rejected_registration',
                ],
                'provider_sync' => [
                    'status' => 'permanent_failure',
                    'provider' => 'zoom',
                    'failure_reason' => 'provider_rejected_registration',
                    'provider_error_code' => '3027',
                    'provider_error_message' => 'Host can not register',
                ],
            ],
        ]);

        app(RetryWebinarRegistrationFinalizationAction::class)->handle(
            registration: $registration,
            operatorId: User::factory()->create()->getKey(),
        );

        $registration->refresh();

        $this->assertSame(
            'pending',
            data_get($registration->meta, 'provider_sync.status'),
        );
        $this->assertNull(
            data_get($registration->meta, 'provider_sync.provider_error_code'),
        );
        $this->assertNull(
            data_get($registration->meta, 'provider_sync.provider_error_message'),
        );
    }

    private function rejectedRegistrationCandidate(): WebinarRegistration
    {
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
        ]);

        return WebinarRegistration::factory()
            ->for($webinar)
            ->create();
    }

    private function registrationPath(
        WebinarSeries $series,
        Webinar|int $webinar,
    ): string {
        $webinarId = $webinar instanceof Webinar
            ? $webinar->getKey()
            : $webinar;

        return URL::signedRoute(
            'webinar.registration.store',
            [
                'seriesSlug' => $series->slug,
                'webinar_id' => $webinarId,
            ],
            absolute: false,
        );
    }

    private function registrationUrl(
        WebinarSeries $series,
        Webinar|int $webinar,
    ): string {
        $showUrl = route('webinar.show', $series->slug);
        $scheme = parse_url($showUrl, PHP_URL_SCHEME);
        $host = parse_url($showUrl, PHP_URL_HOST);
        $port = parse_url($showUrl, PHP_URL_PORT);

        $origin = sprintf(
            '%s://%s%s',
            is_string($scheme) ? $scheme : 'http',
            is_string($host) ? $host : 'localhost',
            is_int($port) ? ':'.$port : '',
        );

        return $origin.$this->registrationPath($series, $webinar);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
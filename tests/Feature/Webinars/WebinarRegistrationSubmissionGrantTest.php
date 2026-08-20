<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Webinars\Actions\FinalizeWebinarRegistrationAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarRegisterPageDefinitionValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class WebinarRegistrationSubmissionGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();

        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'webinar_registrations' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_registrations' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);

        Config::set('webinars.register.content.registration.presentation', 'inline');
        Config::set('webinars.register.content.registration.fields.last_name.enabled', true);
        Config::set('webinars.register.content.registration.questions', []);
        Config::set('webinars.register.content.registration.consents', [
            'transactional' => [
                'email' => false,
                'sms' => true,
                'order' => ['sms'],
                'registration_grants' => ['email'],
                'required_channels' => ['email'],
            ],
            'marketing' => [
                'email' => false,
                'sms' => false,
                'combined' => false,
            ],
        ]);
    }

    public function test_registration_submission_can_grant_transactional_email_without_an_email_checkbox(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'registration-submission-grant',
            'title' => 'Registration Submission Grant',
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDay(),
            'external_id' => null,
        ]);

        $show = $this->get(route('webinar.show', $series->slug));

        $show->assertOk();
        $show->assertSee('name="first_name"', false);
        $show->assertSee('name="last_name"', false);
        $show->assertSee('name="email"', false);
        $show->assertDontSee('name="transactional_email_consent"', false);
        $show->assertSee('name="transactional_sms_consent"', false);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), [
                'company_website' => '',
                'registration_form_ready' => 'ready',
                'registration_form_interacted' => 'human',
                'first_name' => 'Taylor',
                'last_name' => 'Buyer',
                'email' => 'taylor@example.test',
                'phone' => null,
                'transactional_sms_consent' => false,
                'marketing_email_consent' => false,
                'marketing_sms_consent' => false,
            ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors([
            'transactional_email_consent',
            'transactional_consent',
        ]);

        $registration = WebinarRegistration::query()->firstOrFail();
        $consent = MessageConsent::query()
            ->where('contact_id', $registration->contact_id)
            ->where('channel', 'email')
            ->where('purpose', 'transactional')
            ->where('scope', 'webinar')
            ->firstOrFail();

        $this->assertSame(
            ['email'],
            data_get($registration->meta, 'accepted_channels.transactional'),
        );
        $this->assertSame(
            'registration_submission',
            data_get($consent->meta, 'consent_basis'),
        );
        $this->assertDatabaseMissing('message_consents', [
            'contact_id' => $registration->contact_id,
            'channel' => 'sms',
            'purpose' => 'transactional',
            'scope' => 'webinar',
        ]);
        $this->assertDatabaseHas('contacts', [
            'id' => $registration->contact_id,
            'first_name' => 'Taylor',
            'last_name' => 'Buyer',
            'email' => 'taylor@example.test',
        ]);
    }

    public function test_existing_registration_merges_submission_granted_email_into_accepted_channels(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'existing-registration-submission-grant',
            'title' => 'Existing Registration Submission Grant',
        ]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDay(),
            'external_id' => null,
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Taylor',
            'last_name' => 'Buyer',
            'email' => 'existing@example.test',
        ]);
        $registration = WebinarRegistration::factory()
            ->for($contact)
            ->for($webinar)
            ->create([
                'meta' => [
                    'accepted_channels' => [
                        'transactional' => [],
                        'marketing' => [],
                    ],
                ],
            ]);

        $finalize = Mockery::mock(FinalizeWebinarRegistrationAction::class);
        $finalize->shouldReceive('handle')->once()->andReturn(null);
        $this->app->instance(FinalizeWebinarRegistrationAction::class, $finalize);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), [
                'company_website' => '',
                'registration_form_ready' => 'ready',
                'registration_form_interacted' => 'human',
                'first_name' => 'Taylor',
                'last_name' => 'Buyer',
                'email' => 'existing@example.test',
                'phone' => null,
                'transactional_sms_consent' => false,
                'marketing_email_consent' => false,
                'marketing_sms_consent' => false,
            ]);

        $response->assertRedirect();
        $registration->refresh();

        $this->assertSame(
            ['email'],
            data_get($registration->meta, 'accepted_channels.transactional'),
        );
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
        ]);
    }

    public function test_registration_grants_cannot_bypass_a_channel_that_requires_explicit_opt_in(): void
    {
        $violations = app(WebinarRegisterPageDefinitionValidator::class)
            ->validateResolvedDefinition([
                'landing' => [],
                'registration' => [
                    'presentation' => 'inline',
                    'page_revision' => 'test-registration-grant-v1',
                    'questions' => [],
                    'consents' => [
                        'transactional' => [
                            'registration_grants' => ['sms'],
                        ],
                    ],
                    'fields' => [
                        'last_name' => [
                            'enabled' => true,
                        ],
                    ],
                ],
            ], 'webinars.register.test');

        $this->assertContains(
            'webinars.register_page.registration_grant_requires_explicit_opt_in',
            array_column($violations, 'code'),
        );
    }

    private function registrationUrl(
        WebinarSeries $series,
        Webinar $webinar,
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

        return $origin.URL::signedRoute(
            'webinar.registration.store',
            [
                'seriesSlug' => $series->slug,
                'webinar_id' => $webinar->getKey(),
            ],
            absolute: false,
        );
    }
}
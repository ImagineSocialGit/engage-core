<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebinarRegistrationMinimalFieldValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('webinars.registration.bot_protection.enabled', false);
        Config::set('webinars.register.content.registration.fields.last_name.enabled', false);
        Config::set('webinars.register.content.registration.consents', [
            'transactional' => [
                'email' => true,
                'sms' => false,
                'required_channels' => ['email'],
            ],
            'marketing' => [
                'email' => false,
                'sms' => false,
                'combined' => false,
            ],
        ]);

        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'webinar_registrations' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_registrations' => false,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
            ],
        ]);
    }

    public function test_disabled_last_name_can_be_omitted_without_erasing_existing_contact_value(): void
    {
        [$series, $webinar] = $this->webinarFixture('minimal-field-preservation');

        $contact = Contact::factory()->create([
            'first_name' => 'Existing',
            'last_name' => 'Surname',
            'email' => 'existing@example.com',
        ]);

        $this->post($this->registrationUrl($series, $webinar), [
            'first_name' => 'Updated',
            'email' => 'existing@example.com',
            'phone' => null,
            'transactional_email_consent' => true,
        ])->assertRedirect();

        $contact->refresh();

        $this->assertSame('Updated', $contact->first_name);
        $this->assertSame('Surname', $contact->last_name);
    }

    public function test_disabled_last_name_is_still_rejected_when_manually_posted(): void
    {
        [$series, $webinar] = $this->webinarFixture('minimal-field-rejection');

        $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), [
                'first_name' => 'Injected',
                'last_name' => 'Should Not Be Accepted',
                'email' => 'injected@example.com',
                'phone' => null,
                'transactional_email_consent' => true,
            ])
            ->assertRedirect(route('webinar.show', $series->slug))
            ->assertSessionHasErrors('last_name');

        $this->assertDatabaseMissing('contacts', [
            'email' => 'injected@example.com',
        ]);
    }

    /**
     * @return array{0: WebinarSeries, 1: Webinar}
     */
    private function webinarFixture(string $slug): array
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => $slug,
            'title' => 'Configured Minimal Class',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'slug' => $slug.'-occurrence',
            'starts_at' => now()->addDay(),
            'external_id' => null,
        ]);

        return [$series, $webinar];
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
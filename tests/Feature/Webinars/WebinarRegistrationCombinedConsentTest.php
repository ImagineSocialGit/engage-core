<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebinarRegistrationCombinedConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $clientContent = require base_path(
            'client/slam-dunk-crm/config/webinars/register/content.php',
        );

        Config::set(
            'webinars.register.content',
            array_replace_recursive(
                config('webinars.register.content', []),
                $clientContent,
            ),
        );

        $this->configureChannelAvailability();
    }

    public function test_client_form_renders_sms_first_required_email_and_one_combined_marketing_choice(): void
    {
        [$series] = $this->webinarFixture();

        $html = $this->get(route('webinar.show', $series->slug))
            ->assertOk()
            ->getContent();

        $smsPosition = strpos($html, 'name="transactional_sms_consent"');
        $emailPosition = strpos($html, 'name="transactional_email_consent"');

        $this->assertIsInt($smsPosition);
        $this->assertIsInt($emailPosition);
        $this->assertLessThan($emailPosition, $smsPosition);

        $this->assertStringContainsString(
            '(Recommended) Text me webinar reminders and access information.',
            $html,
        );
        $this->assertStringContainsString(
            'name="transactional_email_consent"',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/name="transactional_email_consent"[\s\S]*?required[\s\S]*?aria-required="true"/',
            $html,
        );

        $this->assertStringContainsString('name="marketing_consent"', $html);
        $this->assertStringContainsString(
            'Keep Learning. Send me marketing emails and text messages about future webinars, homebuying tips and educational content from Slam Dunk Home Loans.',
            $html,
        );
        $this->assertStringNotContainsString('name="marketing_email_consent"', $html);
        $this->assertStringNotContainsString('name="marketing_sms_consent"', $html);
    }

    public function test_sms_only_cannot_replace_the_required_transactional_email_consent(): void
    {
        [$series, $webinar] = $this->webinarFixture();

        $this->post($this->registrationUrl($series, $webinar), [
            ...$this->basePayload(),
            'email' => 'sms-only@example.com',
            'phone' => '(615) 555-1212',
            'transactional_email_consent' => false,
            'transactional_sms_consent' => true,
        ])
            ->assertSessionHasErrors('transactional_consent');

        $this->assertDatabaseCount('webinar_registrations', 0);
    }

    public function test_combined_marketing_choice_grants_email_and_sms_marketing_consent(): void
    {
        [$series, $webinar] = $this->webinarFixture();

        $this->post($this->registrationUrl($series, $webinar), [
            ...$this->basePayload(),
            'email' => 'combined-marketing@example.com',
            'phone' => '(615) 555-1212',
            'transactional_email_consent' => true,
            'transactional_sms_consent' => false,
            'marketing_consent' => true,
        ])->assertRedirect();

        $registration = WebinarRegistration::query()->firstOrFail();

        $this->assertSame(
            ['email'],
            data_get($registration->meta, 'accepted_channels.transactional'),
        );
        $this->assertSame(
            ['email', 'sms'],
            data_get($registration->meta, 'accepted_channels.marketing'),
        );

        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $registration->contact_id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
        ]);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $registration->contact_id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar',
        ]);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $registration->contact_id,
            'channel' => MessageChannel::Sms->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar',
        ]);

        $this->assertSame(3, MessageConsent::query()->count());
    }

    /** @return array{0: WebinarSeries, 1: Webinar} */
    private function webinarFixture(): array
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'combined-consent-test',
            'title' => 'Combined Consent Test',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'title' => 'Combined Consent Webinar',
            'slug' => 'combined-consent-webinar',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
            'timezone' => 'America/New_York',
        ]);

        return [$series, $webinar];
    }

    /** @return array<string, mixed> */
    private function basePayload(): array
    {
        return [
            'company_website' => '',
            'registration_form_ready' => 'ready',
            'registration_form_interacted' => 'human',
            'first_name' => 'Test',
            'last_name' => 'Registrant',
            'email' => 'test@example.com',
            'phone' => null,
            'transactional_email_consent' => true,
            'transactional_sms_consent' => false,
            'marketing_consent' => false,
        ];
    }

    private function registrationPath(
        WebinarSeries $series,
        Webinar $webinar,
    ): string {
        return URL::signedRoute(
            'webinar.registration.store',
            [
                'seriesSlug' => $series->slug,
                'webinar_id' => $webinar->getKey(),
            ],
            absolute: false,
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

        return $origin.$this->registrationPath($series, $webinar);
    }

    private function configureChannelAvailability(): void
    {
        foreach (['email', 'sms'] as $channel) {
            Config::set("messaging.channel_availability.{$channel}", [
                'runtime_supported' => true,
                'provider_enabled' => true,
                'requires_explicit_opt_in' => $channel === 'sms',
                'surfaces' => [
                    'webinar_registrations' => true,
                ],
                'purpose_scopes' => [
                    'transactional:webinar' => true,
                    'marketing:webinar_nurture' => true,
                ],
            ]);
        }
    }
}
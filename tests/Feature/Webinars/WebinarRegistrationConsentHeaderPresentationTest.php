<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class WebinarRegistrationConsentHeaderPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_consent_header_body_and_styles_are_configuration_driven(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'consent-header-presentation',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
        ]);

        view()->share('errors', new ViewErrorBag());

        $html = view('components.webinars.registration-form-modal', [
            'page' => $this->page([
                'enabled' => true,
                'body' => 'Configured consent-header body.',
            ]),
            'tokens' => [],
            'style' => [
                'consent_header' => [
                    'wrapper' => 'configured-consent-header-wrapper',
                    'body' => 'configured-consent-header-body',
                ],
            ],
            'series' => $series,
            'webinar' => $webinar,
            'webinarRegistrationChannels' => [
                'transactional' => ['email'],
                'marketing' => ['email'],
            ],
            'registrationPrefill' => [],
        ])->render();

        $this->assertStringContainsString('Configured consent-header body.', $html);
        $this->assertStringContainsString('configured-consent-header-wrapper', $html);
        $this->assertStringContainsString('configured-consent-header-body', $html);
    }

    public function test_consent_header_can_be_disabled(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'disabled-consent-header-presentation',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
        ]);

        view()->share('errors', new ViewErrorBag());

        $html = view('components.webinars.registration-form-modal', [
            'page' => $this->page([
                'enabled' => false,
                'body' => 'Disabled consent-header marker.',
            ]),
            'tokens' => [],
            'style' => [],
            'series' => $series,
            'webinar' => $webinar,
            'webinarRegistrationChannels' => [
                'transactional' => ['email'],
                'marketing' => ['email'],
            ],
            'registrationPrefill' => [],
        ])->render();

        $this->assertStringNotContainsString('Disabled consent-header marker.', $html);
    }

    /**
     * @param array<string, mixed> $consentHeader
     * @return array<string, mixed>
     */
    private function page(array $consentHeader): array
    {
        return [
            'form_card' => [
                'title' => 'Modal title',
                'body' => 'Modal body.',
            ],
            'consent_header' => $consentHeader,
            'consents' => [
                'transactional' => ['email' => true, 'sms' => false],
                'marketing' => ['email' => false, 'sms' => false],
            ],
            'sections' => [
                'notifications' => [
                    'title' => 'Transactional section',
                ],
                'marketing' => [
                    'title' => 'Marketing section',
                ],
            ],
            'fields' => [
                'first_name' => ['label' => 'First Name'],
                'last_name' => ['label' => 'Last Name'],
                'email' => ['label' => 'Email Address'],
                'phone' => ['label' => 'Mobile Phone'],
                'consent_messages' => [
                    'email' => ['label' => 'Transactional email label.'],
                ],
                'marketing_consent_messages' => [
                    'email' => ['label' => 'Marketing email label.'],
                ],
            ],
            'legal_links' => [
                'enabled' => false,
                'links' => [],
            ],
            'submit' => [
                'label' => 'Submit registration',
            ],
        ];
    }
}

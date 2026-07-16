<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class WebinarRegistrationConsentPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_modal_renders_canonical_sections_labels_disclosures_and_legal_links(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'consent-presentation',
        ]);

        view()->share('errors', new ViewErrorBag());

        $html = view('components.webinars.registration-form-modal', [
            'page' => $this->page(),
            'tokens' => [],
            'style' => [],
            'series' => $series,
            'webinarRegistrationChannels' => [
                'transactional' => ['email', 'sms'],
                'marketing' => ['email', 'sms'],
            ],
            'registrationPrefill' => [],
        ])->render();

        $this->assertStringContainsString('Webinar Updates', $html);
        $this->assertStringContainsString('Keep Learning — Optional', $html);
        $this->assertStringContainsString('Webinar email', $html);
        $this->assertStringContainsString('Webinar SMS', $html);
        $this->assertStringContainsString('Marketing email', $html);
        $this->assertStringContainsString('Marketing SMS', $html);

        $this->assertStringNotContainsString('Section 1', $html);
        $this->assertStringNotContainsString('Section 2', $html);

        $this->assertStringContainsString('id="transactional_sms_consent_disclosure"', $html);
        $this->assertStringContainsString('id="marketing_sms_consent_disclosure"', $html);
        $this->assertStringContainsString('Reply STOP to opt out or HELP for help.', $html);

        $this->assertLessThan(
            strpos($html, 'id="transactional_sms_consent_disclosure"'),
            strpos($html, 'Webinar SMS'),
        );
        $this->assertLessThan(
            strpos($html, 'id="marketing_sms_consent_disclosure"'),
            strpos($html, 'Marketing SMS'),
        );

        $this->assertStringContainsString('x-model="transactionalSmsConsent"', $html);
        $this->assertStringContainsString('x-model="marketingSmsConsent"', $html);
        $this->assertStringContainsString('x-bind:required="transactionalSmsConsent || marketingSmsConsent"', $html);
        $this->assertStringContainsString('Required only when you choose an SMS option.', $html);

        $this->assertStringContainsString('href="https://example.test/terms"', $html);
        $this->assertStringContainsString('href="https://example.test/privacy"', $html);
        $this->assertStringNotContainsString('href="#"', $html);
    }

    public function test_registration_modal_hides_sms_labels_and_disclosures_when_sms_is_unavailable(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'email-only-consent-presentation',
        ]);

        view()->share('errors', new ViewErrorBag());

        $html = view('components.webinars.registration-form-modal', [
            'page' => $this->page(),
            'tokens' => [],
            'style' => [],
            'series' => $series,
            'webinarRegistrationChannels' => [
                'transactional' => ['email'],
                'marketing' => ['email'],
            ],
            'registrationPrefill' => [],
        ])->render();

        $this->assertStringContainsString('Webinar email', $html);
        $this->assertStringContainsString('Marketing email', $html);
        $this->assertStringNotContainsString('name="transactional_sms_consent"', $html);
        $this->assertStringNotContainsString('name="marketing_sms_consent"', $html);
        $this->assertStringNotContainsString('transactional_sms_consent_disclosure', $html);
        $this->assertStringNotContainsString('marketing_sms_consent_disclosure', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(): array
    {
        return [
            'form_card' => [
                'title' => 'Reserve Your Spot',
                'body' => 'Register below.',
            ],
            'sections' => [
                'notifications' => [
                    'title' => 'Webinar Updates',
                    'body' => 'Choose at least one way to receive access details and reminders.',
                ],
                'marketing' => [
                    'title' => 'Keep Learning — Optional',
                    'body' => 'Get future tips, resources, and updates after the webinar.',
                ],
            ],
            'fields' => [
                'first_name' => ['label' => 'First Name'],
                'last_name' => ['label' => 'Last Name'],
                'email' => ['label' => 'Email Address'],
                'phone' => [
                    'label' => 'Mobile Phone',
                    'helper' => 'Required only when you choose an SMS option.',
                ],
                'consent_messages' => [
                    'email' => ['label' => 'Webinar email'],
                    'sms' => [
                        'label' => 'Webinar SMS',
                        'disclosure' => 'Webinar SMS disclosure. Reply STOP to opt out or HELP for help.',
                    ],
                ],
                'marketing_consent_messages' => [
                    'email' => ['label' => 'Marketing email'],
                    'sms' => [
                        'label' => 'Marketing SMS',
                        'disclosure' => 'Marketing SMS disclosure. Reply STOP to opt out or HELP for help.',
                    ],
                ],
            ],
            'legal_links' => [
                'enabled' => true,
                'intro' => 'By registering, you agree to our',
                'links' => [
                    ['label' => 'Terms & Conditions', 'url' => 'https://example.test/terms'],
                    ['label' => 'Ignored placeholder', 'url' => '#'],
                    ['label' => 'Privacy Policy', 'url' => 'https://example.test/privacy'],
                ],
            ],
            'submit' => [
                'label' => 'Reserve My Spot',
            ],
        ];
    }
}

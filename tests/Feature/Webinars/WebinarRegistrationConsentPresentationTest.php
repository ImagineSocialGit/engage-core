<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class WebinarRegistrationConsentPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_modal_renders_configured_consent_fields_and_safe_legal_links(): void
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

        foreach ([
            'Configured transactional section',
            'Configured optional marketing section',
            'Configured transactional email label.',
            'Configured transactional email helper.',
            'Configured transactional SMS label.',
            'Configured transactional SMS disclosure.',
            'Configured marketing email label.',
            'Configured marketing SMS label.',
            'Configured marketing SMS disclosure.',
        ] as $configuredText) {
            $this->assertStringContainsString($configuredText, $html);
        }

        foreach ([
            'name="transactional_email_consent"',
            'name="transactional_sms_consent"',
            'name="marketing_email_consent"',
            'name="marketing_sms_consent"',
            'id="transactional_email_consent_helper"',
            'id="transactional_sms_consent_disclosure"',
            'id="marketing_sms_consent_disclosure"',
            'aria-describedby="transactional_email_consent_helper"',
            'aria-describedby="transactional_sms_consent_disclosure"',
            'aria-describedby="marketing_sms_consent_disclosure"',
        ] as $contractFragment) {
            $this->assertStringContainsString($contractFragment, $html);
        }

        $this->assertStringContainsString('x-model="transactionalSmsConsent"', $html);
        $this->assertStringContainsString('x-model="marketingSmsConsent"', $html);
        $this->assertStringContainsString(
            'x-bind:required="transactionalSmsConsent || marketingSmsConsent"',
            $html,
        );
        $this->assertStringContainsString('Configured phone helper.', $html);

        $this->assertStringContainsString('Primary legal notice', $html);
        $this->assertStringContainsString('Data policy', $html);
        $this->assertStringContainsString('href="https://example.test/terms"', $html);
        $this->assertStringContainsString('href="https://example.test/privacy"', $html);
        $this->assertStringNotContainsString('Ignored placeholder', $html);
        $this->assertStringNotContainsString('href="#"', $html);

        $this->assertStringContainsString('x-ref="registrationModal"', $html);
        $this->assertStringContainsString('x-ref="registrationModalClose"', $html);
        $this->assertStringContainsString('@keydown="trapRegistrationModalFocus($event)"', $html);
        $this->assertStringNotContainsString('x-trap', $html);
    }

    public function test_registration_modal_hides_sms_controls_and_guidance_when_sms_is_unavailable(): void
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

        $this->assertStringContainsString('Configured transactional email label.', $html);
        $this->assertStringContainsString('Configured marketing email label.', $html);
        $this->assertStringNotContainsString('name="transactional_sms_consent"', $html);
        $this->assertStringNotContainsString('name="marketing_sms_consent"', $html);
        $this->assertStringNotContainsString('transactional_sms_consent_disclosure', $html);
        $this->assertStringNotContainsString('marketing_sms_consent_disclosure', $html);
        $this->assertStringNotContainsString('phone_sms_helper', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(): array
    {
        return [
            'form_card' => [
                'title' => 'Configured modal title',
                'body' => 'Configured modal body.',
            ],
            'consent_header' => [
                'enabled' => true,
                'body' => 'Configured consent-header body.',
            ],
            'sections' => [
                'notifications' => [
                    'title' => 'Configured transactional section',
                ],
                'marketing' => [
                    'title' => 'Configured optional marketing section',
                ],
            ],
            'fields' => [
                'first_name' => ['label' => 'Configured first-name label'],
                'last_name' => ['label' => 'Configured last-name label'],
                'email' => ['label' => 'Configured email label'],
                'phone' => [
                    'label' => 'Configured phone label',
                    'helper' => 'Configured phone helper.',
                ],
                'consent_messages' => [
                    'email' => [
                        'label' => 'Configured transactional email label.',
                        'helper' => 'Configured transactional email helper.',
                    ],
                    'sms' => [
                        'label' => 'Configured transactional SMS label.',
                        'disclosure' => 'Configured transactional SMS disclosure.',
                    ],
                ],
                'marketing_consent_messages' => [
                    'email' => [
                        'label' => 'Configured marketing email label.',
                    ],
                    'sms' => [
                        'label' => 'Configured marketing SMS label.',
                        'disclosure' => 'Configured marketing SMS disclosure.',
                    ],
                ],
            ],
            'legal_links' => [
                'enabled' => true,
                'intro' => 'Configured legal introduction',
                'links' => [
                    ['label' => 'Primary legal notice', 'url' => 'https://example.test/terms'],
                    ['label' => 'Ignored placeholder', 'url' => '#'],
                    ['label' => 'Data policy', 'url' => 'https://example.test/privacy'],
                ],
            ],
            'submit' => [
                'label' => 'Configured submit label',
            ],
        ];
    }
}

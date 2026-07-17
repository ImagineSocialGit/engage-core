<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class WebinarRegistrationConsentPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_modal_renders_every_configured_and_available_consent_field(): void
    {
        $html = $this->renderModal(
            consents: [
                'transactional' => ['email' => true, 'sms' => true],
                'marketing' => ['email' => true, 'sms' => true],
            ],
            channels: [
                'transactional' => ['email', 'sms'],
                'marketing' => ['email', 'sms'],
            ],
        );

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

    public function test_registration_modal_hides_fields_disabled_by_the_page_contract_even_when_channels_are_available(): void
    {
        $html = $this->renderModal(
            consents: [
                'transactional' => ['email' => true, 'sms' => true],
                'marketing' => ['email' => false, 'sms' => false],
            ],
            channels: [
                'transactional' => ['email', 'sms'],
                'marketing' => ['email', 'sms'],
            ],
        );

        $this->assertStringContainsString('Configured transactional section', $html);
        $this->assertStringContainsString('name="transactional_email_consent"', $html);
        $this->assertStringContainsString('name="transactional_sms_consent"', $html);
        $this->assertStringContainsString('x-model="transactionalSmsConsent"', $html);
        $this->assertStringContainsString('x-bind:required="transactionalSmsConsent"', $html);

        $this->assertStringNotContainsString('Configured optional marketing section', $html);
        $this->assertStringNotContainsString('Configured marketing email label.', $html);
        $this->assertStringNotContainsString('Configured marketing SMS label.', $html);
        $this->assertStringNotContainsString('name="marketing_email_consent"', $html);
        $this->assertStringNotContainsString('name="marketing_sms_consent"', $html);
        $this->assertStringNotContainsString('x-model="marketingSmsConsent"', $html);
    }

    public function test_registration_modal_intersects_configured_fields_with_channel_availability(): void
    {
        $html = $this->renderModal(
            consents: [
                'transactional' => ['email' => true, 'sms' => true],
                'marketing' => ['email' => true, 'sms' => true],
            ],
            channels: [
                'transactional' => ['email'],
                'marketing' => ['email'],
            ],
        );

        $this->assertStringContainsString('Configured transactional email label.', $html);
        $this->assertStringContainsString('Configured marketing email label.', $html);
        $this->assertStringNotContainsString('name="transactional_sms_consent"', $html);
        $this->assertStringNotContainsString('name="marketing_sms_consent"', $html);
        $this->assertStringNotContainsString('transactional_sms_consent_disclosure', $html);
        $this->assertStringNotContainsString('marketing_sms_consent_disclosure', $html);
        $this->assertStringNotContainsString('phone_sms_helper', $html);
        $this->assertStringNotContainsString('x-bind:required=', $html);
    }

    /**
     * @param array<string, array<string, bool>> $consents
     * @param array<string, array<int, string>> $channels
     */
    private function renderModal(array $consents, array $channels): string
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'consent-presentation-'.bin2hex(random_bytes(4)),
        ]);

        view()->share('errors', new ViewErrorBag());

        return view('components.webinars.registration-form-modal', [
            'page' => $this->page($consents),
            'tokens' => [],
            'style' => [],
            'series' => $series,
            'webinarRegistrationChannels' => $channels,
            'registrationPrefill' => [],
        ])->render();
    }

    /**
     * @param array<string, array<string, bool>> $consents
     * @return array<string, mixed>
     */
    private function page(array $consents): array
    {
        return [
            'form_card' => [
                'title' => 'Configured modal title',
                'body' => 'Configured modal body.',
            ],
            'consents' => $consents,
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

<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class WebinarRegistrationConsentPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_modal_renders_the_requested_stacked_consent_copy_and_legal_links(): void
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

        $this->assertStringContainsString('Webinar Registration (Required)', $html);
        $this->assertStringContainsString('Stay Connected (Optional)', $html);

        $this->assertStringContainsString(
            'Email me my webinar confirmation, Zoom link, reminders, replay, and webinar-related updates.',
            $html,
        );
        $this->assertStringContainsString('You may unsubscribe at any time.', $html);
        $this->assertStringContainsString(
            'Text me webinar reminders and access information. (Optional)',
            $html,
        );
        $this->assertStringContainsString(
            'By checking this box, you agree to receive automated text messages related to this webinar. Message frequency varies. Msg &amp; data rates may apply. Reply STOP to opt out.',
            $html,
        );
        $this->assertStringContainsString(
            'Send me marketing emails for future webinars, homebuying tips, loan program updates, and educational content from Slam Dunk Home Loans.',
            $html,
        );
        $this->assertStringContainsString(
            'Send me marketing texts for future webinars, mortgage updates, and educational content from Slam Dunk Home Loans.',
            $html,
        );
        $this->assertStringContainsString(
            'Message frequency varies. Msg &amp; data rates may apply. Reply STOP to opt out.',
            $html,
        );

        $this->assertSame(2, substr_count($html, 'class="mt-3 space-y-4"'));
        $this->assertStringNotContainsString('sm:col-span-2', $html);
        $this->assertStringNotContainsString('Webinar Updates', $html);
        $this->assertStringNotContainsString('Keep Learning — Optional', $html);

        $this->assertStringContainsString('id="transactional_email_consent_helper"', $html);
        $this->assertStringContainsString('id="transactional_sms_consent_disclosure"', $html);
        $this->assertStringContainsString('id="marketing_sms_consent_disclosure"', $html);

        $this->assertLessThan(
            strpos($html, 'id="transactional_email_consent_helper"'),
            strpos($html, 'Email me my webinar confirmation'),
        );
        $this->assertLessThan(
            strpos($html, 'id="transactional_sms_consent_disclosure"'),
            strpos($html, 'Text me webinar reminders'),
        );
        $this->assertLessThan(
            strpos($html, 'id="marketing_sms_consent_disclosure"'),
            strpos($html, 'Send me marketing texts'),
        );

        $this->assertStringContainsString('x-model="transactionalSmsConsent"', $html);
        $this->assertStringContainsString('x-model="marketingSmsConsent"', $html);
        $this->assertStringContainsString(
            'x-bind:required="transactionalSmsConsent || marketingSmsConsent"',
            $html,
        );
        $this->assertStringContainsString('Required to receive SMS.', $html);

        $this->assertStringContainsString('Terms of Service', $html);
        $this->assertStringContainsString('Privacy Policy', $html);
        $this->assertStringContainsString('href="https://example.test/terms"', $html);
        $this->assertStringContainsString('href="https://example.test/privacy"', $html);
        $this->assertStringNotContainsString('href="#"', $html);

        $this->assertStringContainsString('x-ref="registrationModal"', $html);
        $this->assertStringContainsString('x-ref="registrationModalClose"', $html);
        $this->assertStringContainsString('@keydown="trapRegistrationModalFocus($event)"', $html);
        $this->assertStringNotContainsString('x-trap', $html);
    }

    public function test_registration_modal_hides_sms_controls_disclosures_and_phone_guidance_when_sms_is_unavailable(): void
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

        $this->assertStringContainsString('Email me my webinar confirmation', $html);
        $this->assertStringContainsString('Send me marketing emails', $html);
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
                'title' => 'Save Your Seat',
                'body' => 'Register below.',
            ],
            'sections' => [
                'notifications' => [
                    'title' => 'Webinar Registration (Required)',
                ],
                'marketing' => [
                    'title' => 'Stay Connected (Optional)',
                ],
            ],
            'fields' => [
                'first_name' => ['label' => 'First Name'],
                'last_name' => ['label' => 'Last Name'],
                'email' => ['label' => 'Email Address'],
                'phone' => [
                    'label' => 'Mobile Phone',
                    'helper' => 'Required to receive SMS.',
                ],
                'consent_messages' => [
                    'email' => [
                        'label' => 'Email me my webinar confirmation, Zoom link, reminders, replay, and webinar-related updates.',
                        'helper' => 'You may unsubscribe at any time.',
                    ],
                    'sms' => [
                        'label' => 'Text me webinar reminders and access information. (Optional)',
                        'disclosure' => 'By checking this box, you agree to receive automated text messages related to this webinar. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out.',
                    ],
                ],
                'marketing_consent_messages' => [
                    'email' => [
                        'label' => 'Send me marketing emails for future webinars, homebuying tips, loan program updates, and educational content from Slam Dunk Home Loans.',
                    ],
                    'sms' => [
                        'label' => 'Send me marketing texts for future webinars, mortgage updates, and educational content from Slam Dunk Home Loans.',
                        'disclosure' => 'Message frequency varies. Msg & data rates may apply. Reply STOP to opt out.',
                    ],
                ],
            ],
            'legal_links' => [
                'enabled' => true,
                'intro' => 'By registering, you agree to our',
                'links' => [
                    ['label' => 'Terms of Service', 'url' => 'https://example.test/terms'],
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

<?php

namespace Tests\Feature\Webinars;

use Tests\TestCase;

class WebinarRegistrationFrontendContractTest extends TestCase
{
    public function test_registration_frontend_exposes_modal_state_phone_help_and_lightweight_bot_protection(): void
    {
        $script = file_get_contents(resource_path('js/pages/webinar-registration.js'));
        $pageView = file_get_contents(resource_path('views/webinar/register.blade.php'));
        $formView = file_get_contents(resource_path('views/components/webinars/registration-form-modal.blade.php'));

        $this->assertIsString($script);
        $this->assertIsString($pageView);
        $this->assertIsString($formView);

        foreach (['transactionalSmsConsent', 'marketingSmsConsent'] as $property) {
            $this->assertStringContainsString(
                "{$property}: Boolean(config.{$property})",
                $script,
            );
            $this->assertStringContainsString("x-model=\"{$property}\"", $formView);
        }

        foreach (['trapRegistrationModalFocus', 'closeRegistrationModal'] as $method) {
            $this->assertStringContainsString("{$method}(", $script);
            $this->assertStringContainsString($method, $formView);
        }

        $this->assertStringContainsString('x-mask="(999) 999-9999"', $formView);
        $this->assertStringContainsString('pattern="\\(\\d{3}\\) \\d{3}-\\d{4}"', $formView);
        $this->assertStringContainsString("Alpine.directive('mask'", $pageView);
        $this->assertStringContainsString('autocomplete="given-name"', $formView);
        $this->assertStringContainsString('autocomplete="family-name"', $formView);
        $this->assertStringContainsString('autocomplete="email"', $formView);
        $this->assertStringContainsString('autocomplete="tel-national"', $formView);

        foreach ([
            'name="company_website"',
            'name="registration_form_ready"',
            'name="registration_form_interacted"',
            'submitRegistration(event)',
            'transactionalConsentError',
            'x-bind:disabled="submitting || ! registrationFormReady"',
        ] as $contract) {
            $this->assertStringContainsString($contract, $formView);
        }

        $combined = strtolower($pageView.$formView);

        $this->assertStringNotContainsString('recaptcha', $combined);
        $this->assertStringNotContainsString('hcaptcha', $combined);
        $this->assertStringNotContainsString('x-trap', $formView);
    }
}

<?php

namespace Tests\Feature\Webinars;

use Tests\TestCase;

class WebinarRegistrationFrontendContractTest extends TestCase
{
    public function test_alpine_component_exposes_the_sms_state_and_modal_methods_used_by_the_registration_view(): void
    {
        $script = file_get_contents(resource_path('js/pages/webinar-registration.js'));
        $view = file_get_contents(resource_path('views/components/webinars/registration-form-modal.blade.php'));

        $this->assertIsString($script);
        $this->assertIsString($view);

        foreach (['transactionalSmsConsent', 'marketingSmsConsent'] as $property) {
            $this->assertStringContainsString(
                "{$property}: Boolean(config.{$property})",
                $script,
            );
            $this->assertStringContainsString("x-model=\"{$property}\"", $view);
        }

        foreach (['trapRegistrationModalFocus', 'closeRegistrationModal'] as $method) {
            $this->assertStringContainsString("{$method}(", $script);
            $this->assertStringContainsString($method, $view);
        }

        $this->assertStringNotContainsString('x-trap', $view);
    }
}

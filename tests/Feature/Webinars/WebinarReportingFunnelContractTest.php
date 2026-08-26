<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\EventDefinitions\WebinarBehaviorEventDefinitionContributor;
use App\Support\Reporting\Data\ReportingEventDefinition;
use Tests\TestCase;

class WebinarReportingFunnelContractTest extends TestCase
{
    public function test_webinar_contributor_declares_namespaced_browser_funnel_events_for_the_webinar_host(): void
    {
        $definitions = collect(
            app(WebinarBehaviorEventDefinitionContributor::class)->definitions(),
        )->keyBy(fn (ReportingEventDefinition $definition): string => $definition->key);

        $this->assertEqualsCanonicalizing([
            'webinar.page.view',
            'webinar.cta.click',
            'webinar.modal.open',
            'webinar.form.start',
            'webinar.form.submit_attempt',
            'webinar.form.validation_failed',
            'webinar.request.throttled',
            'webinar.bot_protection.result',
            'webinar.engagement.signal',
        ], $definitions->keys()->all());

        $expectedHost = 'webinar.'.strtolower(rtrim((string) config('app.root_domain'), '.'));

        foreach ($definitions as $definition) {
            $this->assertSame(
                [WebinarBehaviorEventDefinitionContributor::SURFACE],
                $definition->surfaces,
            );
            $this->assertSame([$expectedHost], $definition->browserHosts);
            $this->assertSame(
                ReportingEventDefinition::SESSION_EXPECTED,
                $definition->sessionMode,
            );

            $propertyKeys = array_keys($definition->properties);

            $this->assertContains('page_revision', $propertyKeys);
            $this->assertContains('presentation', $propertyKeys);
            $this->assertNotContains('email', $propertyKeys);
            $this->assertNotContains('phone', $propertyKeys);
            $this->assertNotContains('contact_id', $propertyKeys);
            $this->assertNotContains('ip', $propertyKeys);
            $this->assertNotContains('user_agent', $propertyKeys);
        }
    }

    public function test_webinar_reporting_integration_uses_only_shared_reporting_contracts(): void
    {
        $provider = file_get_contents(base_path(
            'app/Modules/Webinars/Providers/WebinarsModuleServiceProvider.php',
        ));
        $contributor = file_get_contents(base_path(
            'app/Modules/Webinars/EventDefinitions/WebinarBehaviorEventDefinitionContributor.php',
        ));

        $this->assertIsString($provider);
        $this->assertIsString($contributor);

        $this->assertStringContainsString(
            "'reporting.event_definition_contributors'",
            $provider,
        );
        $this->assertStringContainsString(
            'App\\Support\\Reporting\\Contracts\\ReportingEventDefinitionContributor',
            $contributor,
        );
        $this->assertStringNotContainsString('App\\Modules\\Reporting', $provider);
        $this->assertStringNotContainsString('App\\Modules\\Reporting', $contributor);
    }

    public function test_webinar_frontend_wires_bounded_funnel_events_and_submit_correlation(): void
    {
        $script = file_get_contents(resource_path('js/pages/webinar-registration.js'));
        $register = file_get_contents(resource_path('views/webinar/register.blade.php'));
        $form = file_get_contents(resource_path(
            'views/components/webinars/registration-form-modal.blade.php',
        ));

        $this->assertIsString($script);
        $this->assertIsString($register);
        $this->assertIsString($form);

        foreach ([
            'webinar.page.view',
            'webinar.cta.click',
            'webinar.modal.open',
            'webinar.form.start',
            'webinar.form.submit_attempt',
            'webinar.form.validation_failed',
            'webinar.request.throttled',
            'webinar.bot_protection.result',
            'webinar.engagement.signal',
        ] as $eventKey) {
            $this->assertStringContainsString($eventKey, $script);
        }

        foreach ([
            "openRegistrationForm('secondary')",
            "openRegistrationForm('final_close')",
            "openRegistrationForm('mobile_primary')",
        ] as $contract) {
            $this->assertStringContainsString($contract, $register);
        }

        $this->assertStringContainsString(
            'name="public_submission_attempt_id"',
            $form,
        );
        $this->assertStringContainsString(
            'prepareRegistrationSubmitAttempt(',
            $form,
        );
        $this->assertStringNotContainsString('reporting_session_token', $form);
    }
}
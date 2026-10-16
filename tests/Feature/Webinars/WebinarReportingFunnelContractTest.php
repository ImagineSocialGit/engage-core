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

}
<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\EventDefinitions\WebinarBehaviorEventDefinitionContributor;
use App\Support\Reporting\Data\ReportingEventDefinition;
use Tests\TestCase;

class WebinarReportingEngagementContractTest extends TestCase
{
    public function test_webinar_reporting_declares_only_bounded_engagement_signals(): void
    {
        $definitions = collect(
            app(WebinarBehaviorEventDefinitionContributor::class)->definitions(),
        )->keyBy(fn (ReportingEventDefinition $definition): string => $definition->key);

        $engagement = $definitions->get('webinar.engagement.signal');

        $this->assertInstanceOf(ReportingEventDefinition::class, $engagement);
        $this->assertFalse($engagement->funnelEligible);
        $this->assertSame(
            ['active_10s', 'scroll_25'],
            $engagement->properties['signal']['values'],
        );
        $this->assertArrayHasKey('page_revision', $engagement->properties);
        $this->assertArrayHasKey('presentation', $engagement->properties);
    }

    public function test_webinar_frontend_records_bounded_time_and_scroll_evidence_without_raw_activity_streams(): void
    {
        $script = file_get_contents(resource_path('js/pages/webinar-registration.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString("'webinar.engagement.signal'", $script);
        $this->assertStringContainsString("signal: 'active_10s'", $script);
        $this->assertStringContainsString("signal: 'scroll_25'", $script);
        $this->assertStringContainsString('document.visibilityState', $script);
        $this->assertStringContainsString('scrollTop / scrollableHeight', $script);
        $this->assertStringNotContainsString('mousemove', $script);
        $this->assertStringNotContainsString('pointermove', $script);
        $this->assertStringNotContainsString('keydown', $script);
    }
}
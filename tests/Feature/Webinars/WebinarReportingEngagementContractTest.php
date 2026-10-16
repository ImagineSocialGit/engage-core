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

}
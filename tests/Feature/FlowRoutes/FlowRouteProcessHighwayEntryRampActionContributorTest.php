<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Services\ProcessHighway\FlowRoutesProcessHighwayEntryRampActionContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteProcessHighwayEntryRampActionContributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_entry_ramp_launcher_deep_links_to_unassigned_route_creation(): void
    {
        $actions = app(FlowRoutesProcessHighwayEntryRampActionContributor::class)->actions(
            'past_client',
            ['attributes' => ['criterion_key' => 'status', 'value' => 'past_client']],
        );

        $this->assertCount(1, $actions);
        $this->assertSame('flow_routes:create_for_status', $actions[0]['key']);
        $this->assertSame('flow_routes', $actions[0]['owner_key']);
        $this->assertSame(
            route('crm.flow-routes.index', ['create' => 1, 'status' => 'past_client']),
            $actions[0]['url'],
        );
    }
}
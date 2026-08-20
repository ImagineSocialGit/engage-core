<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Actions\SyncFlowRouteCapabilitiesAction;
use App\Modules\FlowRoutes\Services\PointHandlerRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTagAutomationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_contact_tag_actions_are_synced_and_runtime_available(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();

        $this->assertDatabaseHas('flow_route_capabilities', [
            'key' => 'core.add_contact_tag',
            'module_key' => 'core',
            'point_type' => 'add_contact_tag',
            'action_key' => 'core.add_contact_tag',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('flow_route_capabilities', [
            'key' => 'core.remove_contact_tag',
            'module_key' => 'core',
            'point_type' => 'remove_contact_tag',
            'action_key' => 'core.remove_contact_tag',
            'is_active' => true,
        ]);

        $handlers = app(PointHandlerRegistry::class);

        $this->assertTrue($handlers->has('add_contact_tag'));
        $this->assertTrue($handlers->has('remove_contact_tag'));
    }
}
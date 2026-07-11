<?php

namespace Tests\Feature\FlowRoutes;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteIndexAuthoringLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_step_route_opens_editor_modal_without_leaving_index(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);

        $user = User::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_active' => true,
        ]);

        $route = FlowRoute::query()->create([
            'key' => 'prospect_route',
            'contact_status_id' => $status->getKey(),
            'name' => 'Prospect Route',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'is_customized' => false,
            'meta' => [],
        ]);

        foreach ([10, 20] as $index => $sortOrder) {
            FlowRoutePoint::query()->create([
                'flow_route_id' => $route->getKey(),
                'key' => 'point_'.$index,
                'type' => FlowRoutePointType::Wait->value,
                'name' => 'Point '.($index + 1),
                'sort_order' => $sortOrder,
                'is_start' => $index === 0,
                'is_active' => true,
                'definition' => ['days' => 1],
                'settings' => [],
                'cancel_conditions' => [],
                'is_customized' => false,
                'meta' => [],
            ]);
        }

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes')
            ->assertOk()
            ->assertSee('Edit Route')
            ->assertSee('openRoute('.$route->getKey().')', false)
            ->assertSee('Route editor')
            ->assertSee('Route flow')
            ->assertDontSee('Back to Routes');
    }
}

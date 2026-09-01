<?php

namespace Tests\Feature\FlowRoutes;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteCreationAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_route_surface_can_be_preselected_by_status_without_assigning_the_new_route(): void
    {
        config()->set('modules.enabled', ['workflow', 'flow_routes']);

        $user = User::factory()->create();
        $status = ContactStatus::query()->create([
            'key' => 'past_client',
            'name' => 'Past Client',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes?create=1&status=past_client')
            ->assertOk()
            ->assertSee('Create Route')
            ->assertSee('data-flow-route-create-modal', false)
            ->assertSee('value="'.$status->getKey().'" selected', false);

        $response = $this->actingAs($user)->post(
            'http://crm.'.config('app.root_domain').'/flow-routes',
            [
                'name' => 'Past Client Follow-Up',
                'description' => 'Keep in touch after closing.',
                'contact_status_id' => $status->getKey(),
            ],
        );

        $route = FlowRoute::query()->sole();

        $response->assertRedirect(route('crm.flow-routes.index', [
            'edit_route' => $route->getKey(),
        ]));

        $this->assertSame('Past Client Follow-Up', $route->name);
        $this->assertSame($status->getKey(), $route->contact_status_id);
        $this->assertSame(FlowRoute::TRIGGER_CONTACT_STATUS, $route->trigger_type);
        $this->assertSame('past_client', $route->trigger_key);
        $this->assertFalse($route->is_active);
        $this->assertTrue($route->is_current_version);
        $this->assertTrue($route->is_customized);
        $this->assertStringStartsWith('crm_route_', (string) $route->key);
        $this->assertSame('crm', data_get($route->meta, 'authoring.source'));
        $this->assertSame(FlowRoute::AUTHORING_KIND_ROUTE, data_get($route->meta, 'authoring.kind'));
        $this->assertSame(0, FlowRouteTriggerBinding::query()->count());
    }

    public function test_inactive_status_cannot_be_used_to_create_a_route(): void
    {
        config()->set('modules.enabled', ['workflow', 'flow_routes']);
        $user = User::factory()->create();
        $status = ContactStatus::query()->create([
            'key' => 'retired',
            'name' => 'Retired',
            'is_active' => false,
            'sort_order' => 10,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from('http://crm.'.config('app.root_domain').'/flow-routes?create=1')
            ->post('http://crm.'.config('app.root_domain').'/flow-routes', [
                'name' => 'Invalid Route',
                'contact_status_id' => $status->getKey(),
            ])
            ->assertSessionHasErrors('contact_status_id');

        $this->assertSame(0, FlowRoute::query()->count());
    }

    public function test_create_automatic_behavior_persists_explicit_kind_and_opens_its_editor(): void
    {
        config()->set('modules.enabled', ['workflow', 'flow_routes']);
        $user = User::factory()->create();
        $status = ContactStatus::query()->create([
            'key' => 'engaged',
            'name' => 'Engaged',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $response = $this->actingAs($user)->post(
            'http://crm.'.config('app.root_domain').'/flow-routes',
            [
                'name' => 'One thing after engagement',
                'authoring_kind' => FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
                'contact_status_id' => $status->getKey(),
            ],
        );

        $route = FlowRoute::query()->sole();

        $response->assertRedirect(route('crm.flow-routes.index', [
            'edit_route' => $route->getKey(),
        ]));
        $this->assertSame(
            FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
            data_get($route->meta, 'authoring.kind'),
        );
        $this->assertFalse($route->is_active);
        $this->assertFalse($route->activeTriggerBindings()->exists());
    }
}
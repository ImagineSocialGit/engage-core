<?php

namespace Tests\Feature\FlowRoutes;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Automation\CoreAutomationTriggerAuthoringContributor;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\InboundMessaging\Automation\InboundReplyAutomationTriggerAuthoringContributor;
use App\Modules\InboundMessaging\Models\InboundReplyIntent;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteTriggerAuthoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_exposes_only_currently_available_module_contributed_triggers(): void
    {
        ContactStatus::query()->create([
            'key' => 'lead',
            'name' => 'Lead',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $keys = app(AutomationTriggerAuthoringRegistry::class)->availableKeys();

        $this->assertContains(CoreAutomationTriggerAuthoringContributor::CONTACT_STATUS, $keys);
        $this->assertContains(CoreAutomationTriggerAuthoringContributor::CONTACT_CREATED, $keys);
        $this->assertNotContains(InboundReplyAutomationTriggerAuthoringContributor::KEY, $keys);
    }

    public function test_reply_outcome_route_is_authored_with_runtime_event_conditions_and_stays_unassigned(): void
    {
        $profile = InboundReplyProfile::query()->create([
            'key' => 'cold_lead_nurture',
            'label' => 'Cold lead nurture replies',
            'description' => null,
            'is_active' => true,
            'source' => 'test',
            'is_customized' => false,
        ]);
        InboundReplyIntent::query()->create([
            'inbound_reply_profile_id' => $profile->getKey(),
            'key' => 'high_intent',
            'label' => 'High Intent',
            'description' => null,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/flow-routes?create=1')
            ->assertOk()
            ->assertSee('Someone replies to a message')
            ->assertSee('Cold lead nurture replies — High Intent')
            ->assertSee('data-flow-route-create-trigger', false);

        $response = $this->actingAs($user)->post(
            'http://crm.'.config('app.root_domain').'/flow-routes',
            [
                'name' => 'Cold lead hand raiser',
                'trigger_authoring_key' => InboundReplyAutomationTriggerAuthoringContributor::KEY,
                'reply_outcome' => 'cold_lead_nurture|high_intent',
            ],
        );

        $route = FlowRoute::query()->sole();

        $response->assertRedirect(route('crm.flow-routes.index', [
            'edit_route' => $route->getKey(),
        ]));
        $this->assertSame(FlowRoute::TRIGGER_AUTOMATION_EVENT, $route->trigger_type);
        $this->assertSame('inbound_message.normal_reply', $route->trigger_key);
        $this->assertNull($route->contact_status_id);
        $this->assertSame(
            'cold_lead_nurture',
            data_get($route->meta, 'definition.entry_conditions.0.value'),
        );
        $this->assertSame(
            'high_intent',
            data_get($route->meta, 'definition.entry_conditions.1.value'),
        );
        $this->assertSame(0, FlowRouteTriggerBinding::query()->count());
    }

    public function test_hidden_trigger_fields_are_disabled_and_only_active_fields_are_required(): void
    {
        ContactStatus::query()->create([
            'key' => 'lead',
            'name' => 'Lead',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs(User::factory()->create())
            ->get('http://crm.'.config('app.root_domain').'/flow-routes?create=1')
            ->assertOk()
            ->assertSee('x-model="createTriggerValues.contact_status_id"', false)
            ->assertSee('x-bind:disabled="createTriggerKey !==', false)
            ->assertSee('x-bind:required="createTriggerKey ===', false)
            ->assertDontSee('createTriggerValues[', false);
    }
}
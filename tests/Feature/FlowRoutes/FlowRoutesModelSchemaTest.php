<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRoutesModelSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_flow_route_supports_active_scope_status_relationship_and_preset_metadata(): void
    {
        $contactStatus = ContactStatus::query()->create([
            'key' => 'new_lead',
            'name' => 'New Lead',
            'description' => 'A new lead.',
            'color' => 'gray',
            'source_version' => '2026-06-26',
        ]);

        $activeRoute = FlowRoute::query()->create([
            'key' => 'general.new_lead',
            'contact_status_id' => $contactStatus->id,
            'name' => 'New Lead Route',
            'description' => 'Route for new leads.',
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $contactStatus->key,
            'is_active' => true,
            'source_version' => '2026-06-26',
            'is_customized' => false,
            'meta' => [
                'source' => 'preset',
            ],
        ]);

        FlowRoute::query()->create([
            'key' => 'general.new_lead',
            'contact_status_id' => $contactStatus->id,
            'name' => 'New Lead Route v2',
            'description' => 'Inactive second version.',
            'version' => 2,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $contactStatus->key,
            'is_active' => false,
            'source_version' => '2026-06-26',
            'is_customized' => true,
            'customized_at' => now(),
            'meta' => [],
        ]);

        $this->assertTrue($activeRoute->is_active);
        $this->assertFalse($activeRoute->is_customized);
        $this->assertSame(['source' => 'preset'], $activeRoute->meta);
        $this->assertSame('general.new_lead', $activeRoute->key);
        $this->assertSame(FlowRoute::TRIGGER_CONTACT_STATUS, $activeRoute->trigger_type);
        $this->assertSame($contactStatus->key, $activeRoute->trigger_key);
        $this->assertTrue($activeRoute->contactStatus->is($contactStatus));

        $this->assertSame(1, FlowRoute::query()->active()->count());
        $this->assertSame(1, FlowRoute::query()->inactive()->count());
        $this->assertSame(2, FlowRoute::query()->forKey('general.new_lead')->count());
        $this->assertSame(1, FlowRoute::query()->forTrigger(FlowRoute::TRIGGER_CONTACT_STATUS, $contactStatus->key)->active()->count());
        $this->assertSame(1, FlowRoute::query()->forContactStatus($contactStatus)->active()->count());
        $this->assertSame(1, FlowRoute::query()->customized()->count());
        $this->assertSame(1, FlowRoute::query()->notCustomized()->count());
    }

    public function test_flow_route_point_is_generic_type_based_and_not_task_specific(): void
    {
        $contactStatus = ContactStatus::query()->create([
            'key' => 'lead',
            'name' => 'Lead',
        ]);

        $route = FlowRoute::query()->create([
            'key' => 'generic_route',
            'contact_status_id' => $contactStatus->id,
            'name' => 'Generic Route',
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $contactStatus->key,
            'is_active' => true,
            'is_customized' => false,
            'meta' => [],
        ]);

        $routePoint = FlowRoutePoint::query()->create([
            'flow_route_id' => $route->id,
            'key' => 'wait_three_days',
            'type' => FlowRoutePointType::Wait->value,
            'name' => 'Wait Three Days',
            'description' => 'Pause route progression for three days.',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [
                'days' => 3,
            ],
            'settings' => [
                'skip_weekends' => false,
            ],
            'cancel_conditions' => [],
            'source_version' => '2026-06-26',
            'is_customized' => false,
            'meta' => [
                'category' => 'timing',
            ],
        ]);

        $this->assertTrue($routePoint->is_active);
        $this->assertSame(FlowRoutePointType::Wait->value, $routePoint->type);
        $this->assertSame(3, $routePoint->definition['days']);
        $this->assertFalse($routePoint->settings['skip_weekends']);
        $this->assertSame('timing', $routePoint->meta['category']);

        $this->assertSame(1, FlowRoutePoint::query()->active()->count());
        $this->assertSame(1, FlowRoutePoint::query()->forPointType(FlowRoutePointType::Wait->value)->count());
        $this->assertSame(1, FlowRoutePoint::query()->forKey('wait_three_days')->count());
        $this->assertSame(1, FlowRoutePoint::query()->notCustomized()->count());
    }

    public function test_flow_route_point_stores_route_specific_configuration(): void
    {
        $contactStatus = ContactStatus::query()->create([
            'key' => 'consultation_scheduled',
            'name' => 'Consultation Scheduled',
        ]);

        $route = FlowRoute::query()->create([
            'key' => 'consultation_scheduled_route',
            'contact_status_id' => $contactStatus->id,
            'name' => 'Consultation Scheduled Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $contactStatus->key,
            'is_active' => true,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $secondRoutePoint = FlowRoutePoint::query()->create([
            'flow_route_id' => $route->id,
            'type' => FlowRoutePointType::SendMessage->value,
            'name' => 'Send Follow-Up Message',
            'description' => null,
            'key' => 'send_follow_up',
            'sort_order' => 20,
            'is_start' => false,
            'is_active' => false,
            'next_flow_route_point_id' => null,
            'definition' => [
                'message_key' => 'consultation.follow_up',
            ],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $firstRoutePoint = FlowRoutePoint::query()->create([
            'flow_route_id' => $route->id,
            'type' => FlowRoutePointType::Wait->value,
            'name' => 'Wait One Day',
            'description' => null,
            'key' => 'custom_wait',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => $secondRoutePoint->getKey(),
            'definition' => [
                'days' => 2,
            ],
            'settings' => [
                'business_days_only' => true,
            ],
            'cancel_conditions' => [
                [
                    'type' => 'contact_status_changed',
                ],
            ],
            'source_version' => '2026-06-26',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [
                'label' => 'custom wait',
            ],
        ]);

        $this->assertTrue($firstRoutePoint->flowRoute->is($route));
        $this->assertSame(FlowRoutePointType::Wait->value, $firstRoutePoint->type);
        $this->assertSame('Wait One Day', $firstRoutePoint->name);
        $this->assertSame('custom_wait', $firstRoutePoint->key);
        $this->assertTrue($firstRoutePoint->is_start);
        $this->assertSame($secondRoutePoint->getKey(), $firstRoutePoint->next_flow_route_point_id);
        $this->assertTrue($firstRoutePoint->nextFlowRoutePoint->is($secondRoutePoint));
        $this->assertSame(2, $firstRoutePoint->definition['days']);
        $this->assertTrue($firstRoutePoint->settings['business_days_only']);
        $this->assertSame('contact_status_changed', $firstRoutePoint->cancel_conditions[0]['type']);
        $this->assertSame('custom wait', $firstRoutePoint->meta['label']);

        $this->assertSame(2, $route->flowRoutePoints()->count());
        $this->assertSame(1, $route->activeFlowRoutePoints()->count());
        $this->assertSame(1, FlowRoutePoint::query()->forKey('custom_wait')->count());
        $this->assertSame(1, FlowRoutePoint::query()->start()->count());
        $this->assertSame(1, FlowRoutePoint::query()->forPointType(FlowRoutePointType::Wait->value)->count());
        $this->assertSame(1, FlowRoutePoint::query()->forPointType(FlowRoutePointType::SendMessage->value)->count());
    }
}

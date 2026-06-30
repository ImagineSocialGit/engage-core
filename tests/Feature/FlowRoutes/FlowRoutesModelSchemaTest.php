<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\Point;
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
            'key' => 'mortgage.new_lead',
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
            'key' => 'mortgage.new_lead',
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
        $this->assertSame('mortgage.new_lead', $activeRoute->key);
        $this->assertSame(FlowRoute::TRIGGER_CONTACT_STATUS, $activeRoute->trigger_type);
        $this->assertSame($contactStatus->key, $activeRoute->trigger_key);
        $this->assertTrue($activeRoute->contactStatus->is($contactStatus));

        $this->assertSame(1, FlowRoute::query()->active()->count());
        $this->assertSame(1, FlowRoute::query()->inactive()->count());
        $this->assertSame(2, FlowRoute::query()->forKey('mortgage.new_lead')->count());
        $this->assertSame(1, FlowRoute::query()->forTrigger(FlowRoute::TRIGGER_CONTACT_STATUS, $contactStatus->key)->active()->count());
        $this->assertSame(1, FlowRoute::query()->forContactStatus($contactStatus)->active()->count());
        $this->assertSame(1, FlowRoute::query()->customized()->count());
        $this->assertSame(1, FlowRoute::query()->notCustomized()->count());
    }

    public function test_point_is_generic_type_based_and_not_task_specific(): void
    {
        $point = Point::query()->create([
            'key' => 'wait_three_days',
            'type' => Point::TYPE_WAIT,
            'name' => 'Wait Three Days',
            'description' => 'Pause route progression for three days.',
            'default_definition' => [
                'days' => 3,
            ],
            'default_settings' => [
                'skip_weekends' => false,
            ],
            'is_active' => true,
            'source_version' => '2026-06-26',
            'is_customized' => false,
            'meta' => [
                'category' => 'timing',
            ],
        ]);

        $this->assertTrue($point->is_active);
        $this->assertTrue($point->isType(Point::TYPE_WAIT));
        $this->assertSame(Point::TYPE_WAIT, $point->type);
        $this->assertSame(3, $point->default_definition['days']);
        $this->assertFalse($point->default_settings['skip_weekends']);
        $this->assertSame('timing', $point->meta['category']);

        $this->assertSame(1, Point::query()->active()->count());
        $this->assertSame(1, Point::query()->type(Point::TYPE_WAIT)->count());
        $this->assertSame(1, Point::query()->forKey('wait_three_days')->count());
        $this->assertSame(1, Point::query()->notCustomized()->count());
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

        $waitPoint = Point::query()->create([
            'key' => 'wait_one_day',
            'type' => Point::TYPE_WAIT,
            'name' => 'Wait One Day',
            'default_definition' => [
                'days' => 1,
            ],
        ]);

        $messagePoint = Point::query()->create([
            'key' => 'send_follow_up_message',
            'type' => Point::TYPE_SEND_MESSAGE,
            'name' => 'Send Follow-Up Message',
            'default_definition' => [
                'message_key' => 'consultation.follow_up',
            ],
        ]);

        $secondRoutePoint = FlowRoutePoint::query()->create([
            'flow_route_id' => $route->id,
            'point_id' => $messagePoint->id,
            'key' => 'send_follow_up',
            'sort_order' => 20,
            'is_start' => false,
            'is_active' => false,
            'next_flow_route_point_id' => null,
            'definition' => [],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $firstRoutePoint = FlowRoutePoint::query()->create([
            'flow_route_id' => $route->id,
            'point_id' => $waitPoint->id,
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
        $this->assertTrue($firstRoutePoint->point->is($waitPoint));
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
        $this->assertSame(1, FlowRoutePoint::query()->forPointType(Point::TYPE_WAIT)->count());
        $this->assertSame(1, FlowRoutePoint::query()->forPointType(Point::TYPE_SEND_MESSAGE)->count());
    }
}
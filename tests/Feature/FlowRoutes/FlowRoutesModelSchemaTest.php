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
        ]);

        $activeRoute = FlowRoute::query()->create([
            'contact_status_id' => $contactStatus->id,
            'name' => 'New Lead Route',
            'version' => 1,
            'is_active' => true,
            'preset_key' => 'mortgage.new_lead',
            'source_version' => '2026-06-26',
            'is_customized' => false,
            'meta' => [
                'source' => 'preset',
            ],
        ]);

        FlowRoute::query()->create([
            'contact_status_id' => $contactStatus->id,
            'name' => 'New Lead Route v2',
            'version' => 2,
            'is_active' => false,
            'preset_key' => 'mortgage.new_lead',
            'source_version' => '2026-06-26',
            'is_customized' => true,
            'customized_at' => now(),
        ]);

        $this->assertTrue($activeRoute->is_active);
        $this->assertFalse($activeRoute->is_customized);
        $this->assertSame(['source' => 'preset'], $activeRoute->meta);
        $this->assertTrue($activeRoute->contactStatus->is($contactStatus));

        $this->assertSame(1, FlowRoute::query()->active()->count());
        $this->assertSame(1, FlowRoute::query()->inactive()->count());
        $this->assertSame(2, FlowRoute::query()->preset('mortgage.new_lead')->count());
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
                'duration' => [
                    'value' => 3,
                    'unit' => 'days',
                ],
            ],
            'default_settings' => [
                'skip_weekends' => false,
            ],
            'is_active' => true,
            'preset_key' => 'core.wait_three_days',
            'source_version' => '2026-06-26',
            'is_customized' => false,
            'meta' => [
                'category' => 'timing',
            ],
        ]);

        $this->assertTrue($point->is_active);
        $this->assertTrue($point->isType(Point::TYPE_WAIT));
        $this->assertSame(Point::TYPE_WAIT, $point->type);
        $this->assertSame(3, $point->default_definition['duration']['value']);
        $this->assertFalse($point->default_settings['skip_weekends']);
        $this->assertSame('timing', $point->meta['category']);

        $this->assertSame(1, Point::query()->active()->count());
        $this->assertSame(1, Point::query()->type(Point::TYPE_WAIT)->count());
        $this->assertSame(1, Point::query()->preset('core.wait_three_days')->count());
        $this->assertSame(1, Point::query()->notCustomized()->count());
    }

    public function test_flow_route_point_stores_route_specific_configuration(): void
    {
        $contactStatus = ContactStatus::query()->create([
            'key' => 'consultation_scheduled',
            'name' => 'Consultation Scheduled',
        ]);

        $route = FlowRoute::query()->create([
            'contact_status_id' => $contactStatus->id,
            'name' => 'Consultation Scheduled Route',
            'version' => 1,
            'is_active' => true,
        ]);

        $waitPoint = Point::query()->create([
            'key' => 'wait_one_day',
            'type' => Point::TYPE_WAIT,
            'name' => 'Wait One Day',
            'default_definition' => [
                'duration' => [
                    'value' => 1,
                    'unit' => 'days',
                ],
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

        $firstRoutePoint = FlowRoutePoint::query()->create([
            'flow_route_id' => $route->id,
            'point_id' => $waitPoint->id,
            'sort_order' => 10,
            'is_active' => true,
            'definition' => [
                'duration' => [
                    'value' => 2,
                    'unit' => 'days',
                ],
            ],
            'settings' => [
                'business_days_only' => true,
            ],
            'cancel_conditions' => [
                [
                    'type' => 'contact_status_changed',
                ],
            ],
            'meta' => [
                'label' => 'custom wait',
            ],
        ]);

        FlowRoutePoint::query()->create([
            'flow_route_id' => $route->id,
            'point_id' => $messagePoint->id,
            'sort_order' => 20,
            'is_active' => false,
        ]);

        $this->assertTrue($firstRoutePoint->flowRoute->is($route));
        $this->assertTrue($firstRoutePoint->point->is($waitPoint));
        $this->assertSame(2, $firstRoutePoint->definition['duration']['value']);
        $this->assertTrue($firstRoutePoint->settings['business_days_only']);
        $this->assertSame('contact_status_changed', $firstRoutePoint->cancel_conditions[0]['type']);
        $this->assertSame('custom wait', $firstRoutePoint->meta['label']);

        $this->assertSame(2, $route->flowRoutePoints()->count());
        $this->assertSame(1, $route->activeFlowRoutePoints()->count());
        $this->assertSame(1, FlowRoutePoint::query()->forPointType(Point::TYPE_WAIT)->count());
        $this->assertSame(1, FlowRoutePoint::query()->forPointType(Point::TYPE_SEND_MESSAGE)->count());
    }
}
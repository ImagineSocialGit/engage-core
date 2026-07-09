<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Services\ContactStatusAutomationImpactResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactStatusAutomationImpactResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_no_automation_when_no_selected_route_exists(): void
    {
        $status = ContactStatus::query()->create([
            'key' => 'new',
            'name' => 'New',
        ]);

        $impact = app(ContactStatusAutomationImpactResolver::class)
            ->forContactStatus($status);

        $this->assertFalse($impact['has_automation']);
        $this->assertSame($status->getKey(), $impact['status_id']);
        $this->assertSame('new', $impact['status_key']);
        $this->assertSame('New', $impact['status_name']);
        $this->assertSame(0, $impact['route_count']);
        $this->assertSame([], $impact['routes']);
    }

    public function test_it_reports_one_selected_route_for_contact_status(): void
    {
        $status = $this->createStatus('prospect', 'Prospect');
        $route = $this->route($status, 'prospect_route', 'Prospect Route');

        $this->bind($status, $route);

        $impact = app(ContactStatusAutomationImpactResolver::class)
            ->forContactStatus($status);

        $this->assertTrue($impact['has_automation']);
        $this->assertSame(1, $impact['route_count']);
        $this->assertSame([
            [
                'id' => $route->getKey(),
                'key' => 'prospect_route',
                'name' => 'Prospect Route',
            ],
        ], $impact['routes']);
    }

    public function test_it_reports_all_selected_routes_for_contact_status(): void
    {
        $status = $this->createStatus('qualified', 'Qualified');
        $firstRoute = $this->route($status, 'qualified_first', 'Qualified First');
        $secondRoute = $this->route($status, 'qualified_second', 'Qualified Second');

        $this->bind($status, $firstRoute);
        $this->bind($status, $secondRoute);

        $impact = app(ContactStatusAutomationImpactResolver::class)
            ->forContactStatus($status);

        $this->assertTrue($impact['has_automation']);
        $this->assertSame(2, $impact['route_count']);
        $this->assertEqualsCanonicalizing(
            [
                [
                    'id' => $firstRoute->getKey(),
                    'key' => 'qualified_first',
                    'name' => 'Qualified First',
                ],
                [
                    'id' => $secondRoute->getKey(),
                    'key' => 'qualified_second',
                    'name' => 'Qualified Second',
                ],
            ],
            $impact['routes'],
        );
    }

    public function test_it_ignores_inactive_bindings_and_inactive_routes(): void
    {
        $status = $this->createStatus('closed', 'Closed');

        $inactiveBindingRoute = $this->route($status, 'inactive_binding_route', 'Inactive Binding Route');
        $inactiveRoute = $this->route($status, 'inactive_route', 'Inactive Route', false);
        $activeRoute = $this->route($status, 'active_route', 'Active Route');

        $this->bind($status, $inactiveBindingRoute, false);
        $this->bind($status, $inactiveRoute);
        $this->bind($status, $activeRoute);

        $impact = app(ContactStatusAutomationImpactResolver::class)
            ->forContactStatus($status);

        $this->assertTrue($impact['has_automation']);
        $this->assertSame(1, $impact['route_count']);
        $this->assertSame('active_route', $impact['routes'][0]['key']);
    }

    public function test_resolution_is_read_only_and_does_not_start_route_progress(): void
    {
        $status = $this->createStatus('attempting_contact', 'Attempting Contact');
        $route = $this->route($status, 'attempting_contact_route', 'Attempting Contact Route');

        $this->bind($status, $route);

        $before = ContactFlowRouteProgress::query()->count();

        $impact = app(ContactStatusAutomationImpactResolver::class)
            ->forContactStatus($status);

        $this->assertTrue($impact['has_automation']);
        $this->assertSame($before, ContactFlowRouteProgress::query()->count());
    }

    public function test_it_returns_empty_impact_for_missing_status_id(): void
    {
        $impact = app(ContactStatusAutomationImpactResolver::class)
            ->forContactStatus(999999);

        $this->assertFalse($impact['has_automation']);
        $this->assertNull($impact['status_id']);
        $this->assertNull($impact['status_key']);
        $this->assertNull($impact['status_name']);
        $this->assertSame(0, $impact['route_count']);
        $this->assertSame([], $impact['routes']);
    }

    private function createStatus(string $key, string $name): ContactStatus
    {
        return ContactStatus::query()->create([
            'key' => $key,
            'name' => $name,
        ]);
    }

    private function route(
        ContactStatus $status,
        string $key,
        string $name,
        bool $active = true,
    ): FlowRoute {
        return FlowRoute::query()->create([
            'key' => $key,
            'contact_status_id' => $status->getKey(),
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => 'system',
            'name' => $name,
            'description' => null,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => $active,
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);
    }

    private function bind(
        ContactStatus $status,
        FlowRoute $route,
        bool $active = true,
    ): FlowRouteTriggerBinding {
        return FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'flow_route_id' => $route->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => $active,
            'meta' => [
                'source' => 'test',
            ],
        ]);
    }
}

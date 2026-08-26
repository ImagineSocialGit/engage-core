<?php

namespace Tests\Feature\Core;

use App\Support\ProcessHighway\ProcessHighwayReadService;
use App\Support\ProcessHighway\ProcessHighwaySemanticKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcessHighwaySurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_highway_remains_available_with_optional_process_modules_disabled(): void
    {
        config()->set('modules.enabled', [
            'core',
        ]);

        $response = $this->actingAs($this->createUser())
            ->get(route('crm.process-highway.index'))
            ->assertOk()
            ->assertViewIs('crm.process-highway.index');

        $highway = $response->viewData('highway');

        $this->assertSame(2, $highway['schema_version']);
        $this->assertSame(0, $highway['subject_count']);
        $this->assertSame(0, $highway['lane_count']);
        $this->assertSame(0, $highway['highway_count']);
        $this->assertSame(0, $highway['segment_count']);
        $this->assertSame(0, $highway['node_count']);
        $this->assertSame(0, $highway['edge_count']);
        $this->assertSame(0, $highway['source_count']);
        $this->assertCount(0, $highway['subjects']);
        $this->assertCount(0, $highway['highways']);
        $this->assertCount(0, $highway['segments']);
        $this->assertCount(0, $highway['qualifier_filters']);
    }


    public function test_status_query_preselects_the_highway_audience_without_requiring_a_matching_process(): void
    {
        config()->set('modules.enabled', [
            'core',
        ]);

        $response = $this->actingAs($this->createUser())
            ->get(route('crm.process-highway.index', [
                'status' => 'past_contact',
            ]))
            ->assertOk();

        $this->assertEquals(
            ['status' => 'past_contact'],
            $response->viewData('initialQualifierSelection'),
        );

        $invalidResponse = $this->actingAs($this->createUser())
            ->get(route('crm.process-highway.index', [
                'status' => ['past_contact'],
            ]))
            ->assertOk();

        $this->assertEquals(
            [],
            $invalidResponse->viewData('initialQualifierSelection'),
        );
    }

    public function test_flow_routes_contribute_owned_nodes_edges_and_exact_edit_targets(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'flow_routes',
            'workflow',
            'tasks',
        ])));

        $statusId = DB::table('contact_statuses')->insertGetId([
            'key' => 'engaged',
            'name' => 'Engaged',
            'is_core' => false,
            'is_active' => true,
            'is_customized' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $routeId = DB::table('flow_routes')->insertGetId([
            'key' => 'process_highway_test_route',
            'contact_status_id' => $statusId,
            'name' => 'Engaged follow-up',
            'description' => 'Fixture process description.',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => 'contact_status',
            'trigger_key' => 'engaged',
            'is_active' => true,
            'is_customized' => false,
            'meta' => json_encode([
                'definition' => [
                    'category' => 'consumer_lifecycle',
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $pointId = DB::table('flow_route_points')->insertGetId([
            'flow_route_id' => $routeId,
            'key' => 'create_follow_up',
            'type' => 'create_task',
            'name' => 'Create follow-up task',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'definition' => json_encode([
                'task_template_key' => 'follow_up',
            ]),
            'settings' => json_encode([]),
            'cancel_conditions' => json_encode([]),
            'is_customized' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $highway = app(ProcessHighwayReadService::class)->read();
        $processKey = ProcessHighwaySemanticKey::flowRoute('process_highway_test_route');
        $segment = collect($highway['segments'])->firstWhere('key', $processKey);

        $this->assertNotNull($segment);
        $this->assertSame('flow_routes', $segment['source_key']);
        $this->assertSame('contacts', $segment['subject_key']);
        $this->assertSame('contacts:standard', $segment['lane_key']);
        $this->assertSame('active', $segment['state']);
        $this->assertSame('contact_status', $segment['attributes']['trigger_type']);
        $this->assertSame('engaged', $segment['attributes']['trigger_key']);
        $this->assertSame($processKey, $segment['mechanism_node_key']);

        $this->assertSame(1, $highway['highway_count']);
        $businessHighway = $highway['highways'][0];
        $this->assertSame('contacts:standard', $businessHighway['lane_key']);
        $this->assertSame([$processKey], $businessHighway['segment_keys']);
        $this->assertSame(['status' => ['engaged']], $businessHighway['qualifiers']);
        $this->assertSame(
            route('crm.flow-routes.show', $routeId),
            $businessHighway['segments'][0]['navigation_target']['url'],
        );

        $nodes = collect($highway['nodes'])->keyBy('key');
        $statusNode = $nodes[ProcessHighwaySemanticKey::status('engaged')];
        $pointNode = $nodes[
            ProcessHighwaySemanticKey::flowRoutePoint(
                'process_highway_test_route',
                'create_follow_up',
            )
        ];

        $this->assertSame('workflow', $statusNode['authority']['owner_key']);
        $this->assertSame('amber', $statusNode['authority']['tone']);
        $this->assertSame('tasks', $pointNode['authority']['owner_key']);
        $this->assertSame('emerald', $pointNode['authority']['tone']);
        $this->assertSame(
            $pointId,
            $pointNode['authority']['edit_targets'][0]['resource']['id'],
        );
        $this->assertSame(
            $routeId,
            $pointNode['authority']['edit_targets'][0]['container']['id'],
        );

        $segmentEdges = collect($highway['edges'])
            ->where('segment_key', $processKey);

        $this->assertTrue($segmentEdges->contains(
            fn (array $edge): bool => $edge['from_node_key'] === $processKey
                && $edge['to_node_key'] === $pointNode['key'],
        ));
        $this->assertTrue($segmentEdges->contains(
            fn (array $edge): bool => $edge['from_node_key'] === $pointNode['key']
                && $edge['role'] === 'exits',
        ));

        $this->actingAs($this->createUser())
            ->get(route('crm.process-highway.index'))
            ->assertOk()
            ->assertSee('data-process-highway', false)
            ->assertSee('data-business-highway', false);
    }

    private function createUser(): \App\Models\User
    {
        return \App\Models\User::factory()->create();
    }
}
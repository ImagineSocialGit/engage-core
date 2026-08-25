<?php

namespace Tests\Feature\FlowRoutes;

use App\Support\ProcessHighway\ProcessHighwayReadService;
use App\Support\ProcessHighway\ProcessHighwaySemanticKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlowRouteProcessHighwayContributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_contributor_exposes_branch_edges_fact_consequences_and_no_match_exit(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'flow_routes',
            'workflow',
            'inbound_messaging',
        ])));

        DB::table('contact_statuses')->insert([
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
            'key' => 'reply_branch_fixture',
            'name' => 'Reply branch fixture',
            'description' => 'Fixture branch Route.',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => 'automation_event',
            'trigger_key' => 'inbound_message.normal_reply',
            'is_active' => true,
            'is_customized' => false,
            'meta' => json_encode([
                'definition' => [
                    'category' => 'consumer_reply',
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $changeStatusPointId = DB::table('flow_route_points')->insertGetId([
            'flow_route_id' => $routeId,
            'key' => 'move_to_engaged',
            'type' => 'change_status',
            'name' => 'Move to Engaged',
            'sort_order' => 20,
            'is_start' => false,
            'is_active' => true,
            'definition' => json_encode([
                'contact_status_key' => 'engaged',
                'reason' => 'fixture',
                'on_same_status' => 'completed',
            ]),
            'settings' => json_encode([]),
            'cancel_conditions' => json_encode([]),
            'is_customized' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('flow_route_points')->insert([
            'flow_route_id' => $routeId,
            'key' => 'match_high_intent',
            'type' => 'branch_evaluate',
            'name' => 'Match high-intent reply',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => $changeStatusPointId,
            'definition' => json_encode([
                'branches' => [[
                    'conditions' => [[
                        'source' => 'execution_meta',
                        'path' => 'automation_event.payload.inbound_message.reply_intent_key',
                        'operator' => 'equals',
                        'value' => 'high_intent',
                    ]],
                    'target_flow_route_point_key' => 'move_to_engaged',
                ]],
                'on_no_match' => 'completed',
            ]),
            'settings' => json_encode([]),
            'cancel_conditions' => json_encode([]),
            'is_customized' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $graph = app(ProcessHighwayReadService::class)->read();
        $processKey = ProcessHighwaySemanticKey::flowRoute('reply_branch_fixture');
        $branchNodeKey = ProcessHighwaySemanticKey::flowRoutePoint(
            'reply_branch_fixture',
            'match_high_intent',
        );
        $statusPointKey = ProcessHighwaySemanticKey::flowRoutePoint(
            'reply_branch_fixture',
            'move_to_engaged',
        );
        $statusFactKey = ProcessHighwaySemanticKey::status('engaged');
        $nodes = collect($graph['nodes'])->keyBy('key');
        $edges = collect($graph['edges'])
            ->where('segment_key', $processKey);

        $this->assertSame(2, $graph['schema_version']);
        $this->assertSame(1, $graph['highway_count']);
        $this->assertSame(1, $graph['segment_count']);

        $businessHighway = $graph['highways'][0];
        $this->assertSame([$processKey], $businessHighway['segment_keys']);
        $this->assertSame(
            [ProcessHighwaySemanticKey::automationEvent('inbound_message.normal_reply')],
            $businessHighway['entry_node_keys'],
        );
        $this->assertSame([], $businessHighway['qualifiers']);
        $this->assertSame(
            route('crm.flow-routes.show', $routeId),
            $businessHighway['segments'][0]['navigation_target']['url'],
        );

        $this->assertSame(
            'inbound_messaging',
            $nodes[ProcessHighwaySemanticKey::automationEvent('inbound_message.normal_reply')]
                ['authority']['owner_key'],
        );
        $this->assertSame(
            'blue',
            $nodes[ProcessHighwaySemanticKey::automationEvent('inbound_message.normal_reply')]
                ['authority']['tone'],
        );
        $this->assertSame('gateway', $nodes[$branchNodeKey]['role']);
        $this->assertSame('workflow', $nodes[$statusPointKey]['authority']['owner_key']);
        $this->assertSame('workflow', $nodes[$statusFactKey]['authority']['owner_key']);
        $this->assertSame('amber', $nodes[$statusFactKey]['authority']['tone']);
        $this->assertSame(
            $changeStatusPointId,
            $nodes[$statusPointKey]['authority']['edit_targets'][0]['resource']['id'],
        );

        $matched = $edges->first(
            fn (array $edge): bool => $edge['from_node_key'] === $branchNodeKey
                && $edge['to_node_key'] === $statusPointKey,
        );
        $noMatch = $edges->first(
            fn (array $edge): bool => $edge['from_node_key'] === $branchNodeKey
                && $edge['label'] === 'No branch matched',
        );
        $statusConsequence = $edges->first(
            fn (array $edge): bool => $edge['from_node_key'] === $statusPointKey
                && $edge['to_node_key'] === $statusFactKey,
        );

        $this->assertNotNull($matched);
        $this->assertSame('branch', $matched['role']);
        $this->assertSame('Reply intent is High Intent', $matched['label']);
        $this->assertNotNull($noMatch);
        $this->assertSame('exits', $noMatch['role']);
        $this->assertNotNull($statusConsequence);
        $this->assertSame('consequence', $statusConsequence['role']);
        $this->assertSame('Changes status to', $statusConsequence['label']);
    }
}
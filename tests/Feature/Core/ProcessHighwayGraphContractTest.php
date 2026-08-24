<?php

namespace Tests\Feature\Core;

use App\Support\ProcessHighway\Contracts\ProcessHighwayContributor;
use App\Support\ProcessHighway\Data\ProcessHighwayAuthority;
use App\Support\ProcessHighway\Data\ProcessHighwayContribution;
use App\Support\ProcessHighway\Data\ProcessHighwayEdge;
use App\Support\ProcessHighway\Data\ProcessHighwayEditTarget;
use App\Support\ProcessHighway\Data\ProcessHighwayLane;
use App\Support\ProcessHighway\Data\ProcessHighwayNode;
use App\Support\ProcessHighway\ProcessHighwayGraphComposer;
use App\Support\ProcessHighway\ProcessHighwaySemanticKey;
use InvalidArgumentException;
use Tests\TestCase;

class ProcessHighwayGraphContractTest extends TestCase
{
    public function test_graph_composes_shared_entry_facts_convergence_and_an_exit_into_another_process(): void
    {
        $statusKey = ProcessHighwaySemanticKey::status('engaged');
        $campaignKey = ProcessHighwaySemanticKey::campaign('fixture_campaign');
        $routeKey = ProcessHighwaySemanticKey::flowRoute('fixture_route');
        $campaignEdit = $this->editTarget(
            ownerKey: 'campaigns',
            resourceType: 'campaign',
            resourceKey: 'fixture_campaign',
            url: '/campaigns/1/edit',
        );
        $routeEdit = $this->editTarget(
            ownerKey: 'flow_routes',
            resourceType: 'flow_route',
            resourceKey: 'fixture_route',
            url: '/flow-routes/2',
        );

        $campaign = new ProcessHighwayContribution(
            sourceKey: 'campaigns',
            key: $campaignKey,
            name: 'Fixture Campaign',
            description: 'Fixture campaign process.',
            subjectKey: 'contacts',
            lane: ProcessHighwayLane::standard(),
            processNodeKey: $campaignKey,
            authority: new ProcessHighwayAuthority('campaigns', [$campaignEdit]),
            nodes: [
                new ProcessHighwayNode(
                    key: $statusKey,
                    label: 'Status: Engaged',
                    role: ProcessHighwayNode::ROLE_QUALIFIER,
                    authority: new ProcessHighwayAuthority('workflow', [$campaignEdit]),
                    referenceOnly: true,
                ),
                new ProcessHighwayNode(
                    key: $campaignKey,
                    label: 'Fixture Campaign',
                    role: ProcessHighwayNode::ROLE_PROCESS,
                    authority: new ProcessHighwayAuthority('campaigns', [$campaignEdit]),
                ),
                new ProcessHighwayNode(
                    key: $routeKey,
                    label: 'Fixture Route',
                    role: ProcessHighwayNode::ROLE_PROCESS,
                    authority: new ProcessHighwayAuthority('flow_routes', [$campaignEdit]),
                    referenceOnly: true,
                ),
            ],
            edges: [
                new ProcessHighwayEdge(
                    key: $campaignKey.':edge:start',
                    fromNodeKey: $statusKey,
                    toNodeKey: $campaignKey,
                    role: ProcessHighwayEdge::ROLE_STARTS,
                    authority: new ProcessHighwayAuthority('campaigns', [$campaignEdit]),
                ),
                new ProcessHighwayEdge(
                    key: $campaignKey.':edge:route',
                    fromNodeKey: $campaignKey,
                    toNodeKey: $routeKey,
                    role: ProcessHighwayEdge::ROLE_EXITS_TO,
                    authority: new ProcessHighwayAuthority('campaigns', [$campaignEdit]),
                ),
            ],
            entryNodeKeys: [$statusKey],
            exitNodeKeys: [$routeKey],
        );

        $routeExitKey = $routeKey.':exit:completed';
        $route = new ProcessHighwayContribution(
            sourceKey: 'flow_routes',
            key: $routeKey,
            name: 'Fixture Route',
            description: 'Fixture route process.',
            subjectKey: 'contacts',
            lane: ProcessHighwayLane::standard(),
            processNodeKey: $routeKey,
            authority: new ProcessHighwayAuthority('flow_routes', [$routeEdit]),
            nodes: [
                new ProcessHighwayNode(
                    key: $statusKey,
                    label: 'Status: Engaged',
                    role: ProcessHighwayNode::ROLE_QUALIFIER,
                    authority: new ProcessHighwayAuthority('workflow', [$routeEdit]),
                    referenceOnly: true,
                ),
                new ProcessHighwayNode(
                    key: $routeKey,
                    label: 'Fixture Route',
                    role: ProcessHighwayNode::ROLE_PROCESS,
                    authority: new ProcessHighwayAuthority('flow_routes', [$routeEdit]),
                ),
                new ProcessHighwayNode(
                    key: $routeExitKey,
                    label: 'Route completed',
                    role: ProcessHighwayNode::ROLE_EXIT,
                    authority: new ProcessHighwayAuthority('flow_routes', [$routeEdit]),
                ),
            ],
            edges: [
                new ProcessHighwayEdge(
                    key: $routeKey.':edge:start',
                    fromNodeKey: $statusKey,
                    toNodeKey: $routeKey,
                    role: ProcessHighwayEdge::ROLE_STARTS,
                    authority: new ProcessHighwayAuthority('flow_routes', [$routeEdit]),
                ),
                new ProcessHighwayEdge(
                    key: $routeKey.':edge:complete',
                    fromNodeKey: $routeKey,
                    toNodeKey: $routeExitKey,
                    role: ProcessHighwayEdge::ROLE_EXITS,
                    authority: new ProcessHighwayAuthority('flow_routes', [$routeEdit]),
                ),
            ],
            entryNodeKeys: [$statusKey],
            exitNodeKeys: [$routeExitKey],
        );

        $graph = app(ProcessHighwayGraphComposer::class)->compose([
            $this->contributor([$campaign]),
            $this->contributor([$route]),
        ]);

        $this->assertSame(1, $graph['schema_version']);
        $this->assertSame(1, $graph['subject_count']);
        $this->assertSame(1, $graph['lane_count']);
        $this->assertSame(2, $graph['process_count']);
        $this->assertSame(2, $graph['source_count']);

        $subject = $graph['subjects'][0];
        $this->assertSame('contacts', $subject['key']);
        $this->assertSame('contacts:standard', $subject['lanes'][0]['key']);
        $this->assertEqualsCanonicalizing(
            [$campaignKey, $routeKey],
            $subject['lanes'][0]['process_keys'],
        );

        $nodes = collect($graph['nodes'])->keyBy('key');
        $status = $nodes[$statusKey];
        $composedRoute = $nodes[$routeKey];

        $this->assertSame('workflow', $status['authority']['owner_key']);
        $this->assertSame('amber', $status['authority']['tone']);
        $this->assertCount(2, $status['authority']['edit_targets']);
        $this->assertEqualsCanonicalizing(
            [$campaignKey, $routeKey],
            $status['process_keys'],
        );

        $this->assertFalse($composedRoute['reference_only']);
        $this->assertSame('flow_routes', $composedRoute['authority']['owner_key']);
        $this->assertSame('orange', $composedRoute['authority']['tone']);
        $this->assertCount(2, $composedRoute['authority']['edit_targets']);

        $edges = collect($graph['edges'])->keyBy('key');
        $this->assertSame(
            ProcessHighwayEdge::ROLE_EXITS_TO,
            $edges[$campaignKey.':edge:route']['role'],
        );
        $this->assertSame(
            $routeKey,
            $edges[$campaignKey.':edge:route']['to_node_key'],
        );
        $this->assertSame(
            'rose',
            $edges[$campaignKey.':edge:route']['authority']['tone'],
        );
    }

    public function test_every_visible_graph_element_requires_an_authoritative_edit_target(): void
    {
        $processKey = ProcessHighwaySemanticKey::campaign('missing_editor');
        $authority = new ProcessHighwayAuthority('campaigns', []);
        $contribution = new ProcessHighwayContribution(
            sourceKey: 'campaigns',
            key: $processKey,
            name: 'Missing editor',
            description: '',
            subjectKey: 'contacts',
            lane: ProcessHighwayLane::standard(),
            processNodeKey: $processKey,
            authority: $authority,
            nodes: [
                new ProcessHighwayNode(
                    key: $processKey,
                    label: 'Missing editor',
                    role: ProcessHighwayNode::ROLE_PROCESS,
                    authority: $authority,
                ),
            ],
            edges: [],
            entryNodeKeys: [$processKey],
            exitNodeKeys: [$processKey],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare at least one authoritative edit target');

        app(ProcessHighwayGraphComposer::class)->compose([
            $this->contributor([$contribution]),
        ]);
    }

    /** @param array<int, ProcessHighwayContribution> $contributions */
    private function contributor(array $contributions): ProcessHighwayContributor
    {
        return new class($contributions) implements ProcessHighwayContributor {
            /** @param array<int, ProcessHighwayContribution> $contributions */
            public function __construct(
                private readonly array $contributions,
            ) {}

            public function contributions(): iterable
            {
                return $this->contributions;
            }
        };
    }

    private function editTarget(
        string $ownerKey,
        string $resourceType,
        string $resourceKey,
        string $url,
    ): ProcessHighwayEditTarget {
        return ProcessHighwayEditTarget::link(
            ownerKey: $ownerKey,
            label: 'Edit fixture',
            url: $url,
            resourceType: $resourceType,
            resourceKey: $resourceKey,
        );
    }
}
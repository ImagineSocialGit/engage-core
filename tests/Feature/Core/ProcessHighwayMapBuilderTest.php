<?php

namespace Tests\Feature\Core;

use App\Support\ProcessHighway\ProcessHighwayMapBuilder;
use Tests\TestCase;

class ProcessHighwayMapBuilderTest extends TestCase
{
    public function test_it_builds_business_highways_from_connected_segments_without_crossing_lanes(): void
    {
        $standardCampaign = $this->segment(
            key: 'campaigns:campaign:engaged_nurture',
            sourceKey: 'campaigns',
            laneKey: 'contacts:standard',
            nodeKeys: ['workflow:status:engaged', 'campaigns:campaign:engaged_nurture', 'campaigns:campaign:engaged_nurture:exit'],
            entryNodeKeys: ['workflow:status:engaged'],
            exitNodeKeys: ['campaigns:campaign:engaged_nurture:exit'],
        );
        $standardRoute = $this->segment(
            key: 'flow_routes:route:engaged_reply',
            sourceKey: 'flow_routes',
            laneKey: 'contacts:standard',
            nodeKeys: ['workflow:status:engaged', 'flow_routes:route:engaged_reply', 'flow_routes:route:engaged_reply:exit'],
            entryNodeKeys: ['workflow:status:engaged'],
            exitNodeKeys: ['flow_routes:route:engaged_reply:exit'],
        );
        $vipCampaign = $this->segment(
            key: 'campaigns:campaign:vip',
            sourceKey: 'campaigns',
            laneKey: 'contacts:standard',
            nodeKeys: ['core:contact_tag:present:VIP', 'campaigns:campaign:vip', 'campaigns:campaign:vip:exit'],
            entryNodeKeys: ['core:contact_tag:present:VIP'],
            exitNodeKeys: ['campaigns:campaign:vip:exit'],
        );
        $relationshipCampaign = $this->segment(
            key: 'campaigns:campaign:realtor_engaged',
            sourceKey: 'campaigns',
            laneKey: 'contacts:relationship:realtor',
            nodeKeys: ['workflow:status:engaged', 'campaigns:campaign:realtor_engaged', 'campaigns:campaign:realtor_engaged:exit'],
            entryNodeKeys: ['workflow:status:engaged'],
            exitNodeKeys: ['campaigns:campaign:realtor_engaged:exit'],
        );
        $segments = [
            $standardCampaign,
            $standardRoute,
            $vipCampaign,
            $relationshipCampaign,
        ];
        $nodes = [
            $this->node(
                key: 'workflow:status:engaged',
                label: 'Status: Engaged',
                segmentKeys: [
                    $standardCampaign['key'],
                    $standardRoute['key'],
                    $relationshipCampaign['key'],
                ],
                criterionKey: 'status',
                value: 'engaged',
            ),
            $this->node(
                key: 'core:contact_tag:present:VIP',
                label: 'Tag: VIP',
                segmentKeys: [$vipCampaign['key']],
                criterionKey: 'tag',
                value: 'VIP',
                ownerKey: 'core',
            ),
            ...$this->mechanismAndExitNodes($standardCampaign),
            ...$this->mechanismAndExitNodes($standardRoute),
            ...$this->mechanismAndExitNodes($vipCampaign),
            ...$this->mechanismAndExitNodes($relationshipCampaign),
        ];
        $edges = [
            ...$this->segmentEdges($standardCampaign),
            ...$this->segmentEdges($standardRoute),
            ...$this->segmentEdges($vipCampaign),
            ...$this->segmentEdges($relationshipCampaign),
        ];
        $subjects = [[
            'key' => 'contacts',
            'label' => 'Contacts',
            'lanes' => [
                [
                    'key' => 'contacts:standard',
                    'label' => 'Standard contacts',
                    'subject_key' => 'contacts',
                    'scope' => 'standard',
                    'relationship_key' => null,
                    'relationship_label' => null,
                    'sort_order' => 10,
                    'segment_keys' => [
                        $standardCampaign['key'],
                        $standardRoute['key'],
                        $vipCampaign['key'],
                    ],
                ],
                [
                    'key' => 'contacts:relationship:realtor',
                    'label' => 'Realtor relationships',
                    'subject_key' => 'contacts',
                    'scope' => 'relationship',
                    'relationship_key' => 'realtor',
                    'relationship_label' => 'Realtor',
                    'sort_order' => 20,
                    'segment_keys' => [$relationshipCampaign['key']],
                ],
            ],
        ]];

        $map = app(ProcessHighwayMapBuilder::class)->build(
            segments: $segments,
            nodes: $nodes,
            edges: $edges,
            subjects: $subjects,
        );

        $this->assertSame(3, $map['highway_count']);

        $connected = collect($map['highways'])->first(
            fn (array $highway): bool => count($highway['segment_keys']) === 2,
        );

        $this->assertNotNull($connected);
        $this->assertSame('contacts:standard', $connected['lane_key']);
        $this->assertEqualsCanonicalizing([
            $standardCampaign['key'],
            $standardRoute['key'],
        ], $connected['segment_keys']);
        $this->assertSame(['status' => ['engaged']], $connected['qualifiers']);
        $this->assertSame('all', $connected['entry_operator']);
        $this->assertSame('status', $connected['entry_requirements'][0]['criterion_key']);
        $this->assertSame('any', $connected['entry_requirements'][0]['operator']);
        $this->assertSame('engaged', $connected['entry_requirements'][0]['values'][0]['value']);
        $this->assertSame('Engaged', $connected['name']);

        $relationshipHighway = collect($map['highways'])->firstWhere(
            'lane_key',
            'contacts:relationship:realtor',
        );

        $this->assertNotNull($relationshipHighway);
        $this->assertSame([$relationshipCampaign['key']], $relationshipHighway['segment_keys']);

        $standardLane = $map['subjects'][0]['lanes'][0];
        $relationshipLane = $map['subjects'][0]['lanes'][1];

        $this->assertSame(2, $standardLane['highway_count']);
        $this->assertSame(1, $relationshipLane['highway_count']);

        $filters = collect($map['qualifier_filters'])->keyBy('key');

        $this->assertEqualsCanonicalizing(
            [$connected['key'], $relationshipHighway['key']],
            $filters['status']['options'][0]['highway_keys'],
        );
        $this->assertSame('VIP', $filters['tag']['options'][0]['label']);
    }

    public function test_navigation_uses_safe_links_while_retaining_inline_capabilities(): void
    {
        $segment = $this->segment(
            key: 'campaigns:campaign:past_client',
            sourceKey: 'campaigns',
            laneKey: 'contacts:standard',
            nodeKeys: ['workflow:status:past_client', 'campaigns:campaign:past_client', 'campaigns:campaign:past_client:exit'],
            entryNodeKeys: ['workflow:status:past_client'],
            exitNodeKeys: ['campaigns:campaign:past_client:exit'],
        );
        $statusNode = $this->node(
            key: 'workflow:status:past_client',
            label: 'Status: Past Client',
            segmentKeys: [$segment['key']],
            criterionKey: 'status',
            value: 'past_client',
        );
        $subjects = [[
            'key' => 'contacts',
            'label' => 'Contacts',
            'lanes' => [[
                'key' => 'contacts:standard',
                'label' => 'Standard contacts',
                'subject_key' => 'contacts',
                'scope' => 'standard',
                'relationship_key' => null,
                'relationship_label' => null,
                'sort_order' => 10,
                'segment_keys' => [$segment['key']],
            ]],
        ]];

        $map = app(ProcessHighwayMapBuilder::class)->build(
            segments: [$segment],
            nodes: [
                $statusNode,
                ...$this->mechanismAndExitNodes($segment),
            ],
            edges: $this->segmentEdges($segment),
            subjects: $subjects,
        );

        $entry = $map['highways'][0]['entry_nodes'][0];

        $this->assertSame('link', $entry['navigation_target']['mode']);
        $this->assertSame('GET', $entry['navigation_target']['method']);
        $this->assertSame('/campaigns/past-client/edit', $entry['navigation_target']['url']);
        $this->assertSame('inline', $entry['authority']['edit_targets'][0]['mode']);
        $this->assertSame('PATCH', $entry['authority']['edit_targets'][0]['method']);
    }

    public function test_reply_acknowledgements_attach_to_the_matching_business_branch(): void
    {
        $statusKey = 'workflow:status:past_contact';
        $routing = $this->segment(
            key: 'flow_routes:route:past_client_reply',
            sourceKey: 'flow_routes',
            laneKey: 'contacts:standard',
            nodeKeys: [$statusKey, 'flow_routes:route:past_client_reply', 'flow_routes:route:past_client_reply:exit'],
            entryNodeKeys: [$statusKey],
            exitNodeKeys: ['flow_routes:route:past_client_reply:exit'],
        );
        $routing['attributes'] = [
            'role' => 'reply_routing',
            'reply_profile_keys' => ['past_client_nurture'],
            'reply_intent_keys' => ['high_intent'],
            'reply_channels' => [],
        ];
        $acknowledgement = $this->segment(
            key: 'flow_routes:route:past_client_email_ack',
            sourceKey: 'flow_routes',
            laneKey: 'contacts:standard',
            nodeKeys: [$statusKey, 'flow_routes:route:past_client_email_ack', 'flow_routes:route:past_client_email_ack:exit'],
            entryNodeKeys: [$statusKey],
            exitNodeKeys: ['flow_routes:route:past_client_email_ack:exit'],
        );
        $acknowledgement['attributes'] = [
            'role' => 'reply_messaging',
            'reply_profile_keys' => ['past_client_nurture'],
            'reply_intent_keys' => ['high_intent'],
            'reply_channels' => ['email'],
        ];
        $subjects = [[
            'key' => 'contacts',
            'label' => 'Contacts',
            'lanes' => [[
                'key' => 'contacts:standard',
                'label' => 'Standard contacts',
                'subject_key' => 'contacts',
                'scope' => 'standard',
                'relationship_key' => null,
                'relationship_label' => null,
                'sort_order' => 10,
                'segment_keys' => [$routing['key'], $acknowledgement['key']],
            ]],
        ]];

        $map = app(ProcessHighwayMapBuilder::class)->build(
            segments: [$routing, $acknowledgement],
            nodes: [
                $this->node($statusKey, 'Status: Past Client', [$routing['key'], $acknowledgement['key']], 'status', 'past_contact'),
                ...$this->mechanismAndExitNodes($routing),
                ...$this->mechanismAndExitNodes($acknowledgement),
            ],
            edges: [
                ...$this->segmentEdges($routing),
                ...$this->segmentEdges($acknowledgement),
            ],
            subjects: $subjects,
        );

        $routingSegment = collect($map['highways'][0]['segments'])->firstWhere('key', $routing['key']);

        $this->assertNotNull($routingSegment);
        $this->assertSame(1, count($routingSegment['supporting_acknowledgements']));
        $this->assertSame($acknowledgement['key'], $routingSegment['supporting_acknowledgements'][0]['key']);
        $this->assertSame(['email'], $routingSegment['supporting_acknowledgements'][0]['channels']);
    }

    /**
     * @param array<int, string> $nodeKeys
     * @param array<int, string> $entryNodeKeys
     * @param array<int, string> $exitNodeKeys
     * @return array<string, mixed>
     */
    private function segment(
        string $key,
        string $sourceKey,
        string $laneKey,
        array $nodeKeys,
        array $entryNodeKeys,
        array $exitNodeKeys,
    ): array {
        return [
            'source_key' => $sourceKey,
            'key' => $key,
            'name' => str($key)->afterLast(':')->headline()->toString(),
            'description' => 'Fixture mechanism.',
            'subject_key' => 'contacts',
            'lane_key' => $laneKey,
            'mechanism_node_key' => $key,
            'node_keys' => $nodeKeys,
            'edge_keys' => [$key.':edge:start', $key.':edge:exit'],
            'entry_node_keys' => $entryNodeKeys,
            'exit_node_keys' => $exitNodeKeys,
            'state' => 'active',
            'state_label' => 'Active',
            'entry_summary' => 'A fixture fact becomes true.',
            'sort_order' => 100,
            'details' => [],
            'authority' => $this->authority($sourceKey),
            'attributes' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function node(
        string $key,
        string $label,
        array $segmentKeys,
        ?string $criterionKey = null,
        ?string $value = null,
        ?string $ownerKey = null,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'description' => null,
            'detail' => null,
            'role' => $criterionKey === null ? 'process' : 'qualifier',
            'state' => 'configured',
            'state_label' => 'Configured',
            'sort_order' => 10,
            'reference_only' => $criterionKey !== null,
            'authority' => $this->authority(
                $ownerKey ?? ($criterionKey === null ? 'campaigns' : 'workflow'),
            ),
            'attributes' => $criterionKey === null ? [] : [
                'criterion_key' => $criterionKey,
                'value' => $value,
                'value_label' => $label === 'Tag: VIP' ? 'VIP' : str($value)->headline()->toString(),
            ],
            'segment_keys' => $segmentKeys,
        ];
    }

    /**
     * @param array<string, mixed> $segment
     * @return array<int, array<string, mixed>>
     */
    private function mechanismAndExitNodes(array $segment): array
    {
        $exitKey = $segment['exit_node_keys'][0];

        return [
            $this->node(
                key: $segment['key'],
                label: $segment['name'],
                segmentKeys: [$segment['key']],
                ownerKey: $segment['source_key'],
            ),
            [
                ...$this->node(
                    key: $exitKey,
                    label: 'Completed',
                    segmentKeys: [$segment['key']],
                    ownerKey: $segment['source_key'],
                ),
                'role' => 'exit',
                'sort_order' => 300,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $segment
     * @return array<int, array<string, mixed>>
     */
    private function segmentEdges(array $segment): array
    {
        return [
            [
                'key' => $segment['key'].':edge:start',
                'from_node_key' => $segment['entry_node_keys'][0],
                'to_node_key' => $segment['key'],
                'role' => 'starts',
                'label' => 'Starts',
                'sort_order' => 10,
                'authority' => $this->authority($segment['source_key']),
                'attributes' => [],
                'segment_key' => $segment['key'],
            ],
            [
                'key' => $segment['key'].':edge:exit',
                'from_node_key' => $segment['key'],
                'to_node_key' => $segment['exit_node_keys'][0],
                'role' => 'exits',
                'label' => 'Completes',
                'sort_order' => 20,
                'authority' => $this->authority($segment['source_key']),
                'attributes' => [],
                'segment_key' => $segment['key'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function authority(string $ownerKey): array
    {
        return [
            'owner_key' => $ownerKey,
            'owner_label' => str($ownerKey)->headline()->toString(),
            'tone' => 'slate',
            'editable' => true,
            'edit_targets' => [
                [
                    'mode' => 'inline',
                    'owner_key' => 'campaigns',
                    'owner_label' => 'Campaigns',
                    'label' => 'Edit inline',
                    'url' => '/campaigns/past-client/eligibility',
                    'method' => 'PATCH',
                    'capability' => 'campaigns.eligibility.update',
                    'resource' => ['type' => 'campaign', 'key' => 'past_client', 'id' => 1],
                    'container' => null,
                ],
                [
                    'mode' => 'link',
                    'owner_key' => $ownerKey,
                    'owner_label' => str($ownerKey)->headline()->toString(),
                    'label' => 'Open owner',
                    'url' => '/campaigns/past-client/edit',
                    'method' => 'GET',
                    'capability' => null,
                    'resource' => ['type' => 'fixture', 'key' => 'fixture', 'id' => 1],
                    'container' => null,
                ],
            ],
        ];
    }
}
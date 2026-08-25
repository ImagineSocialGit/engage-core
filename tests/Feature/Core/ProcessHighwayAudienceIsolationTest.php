<?php

namespace Tests\Feature\Core;

use App\Support\ProcessHighway\ProcessHighwayMapBuilder;
use Tests\TestCase;

class ProcessHighwayAudienceIsolationTest extends TestCase
{
    public function test_shared_downstream_contact_facts_do_not_merge_unrelated_audiences(): void
    {
        $coldKey = 'campaigns:campaign:cold_lead';
        $pastKey = 'campaigns:campaign:past_client';
        $coldEntryKey = 'workflow:status:prospect_nurture';
        $pastEntryKey = 'workflow:status:past_contact';
        $sharedOutcomeKey = 'workflow:status:engaged';
        $segments = [
            $this->segment($coldKey, $coldEntryKey, $sharedOutcomeKey),
            $this->segment($pastKey, $pastEntryKey, $sharedOutcomeKey),
        ];
        $nodes = [
            $this->factNode($coldEntryKey, 'Status: Prospect – Nurture', 'prospect_nurture', [$coldKey]),
            $this->factNode($pastEntryKey, 'Status: Past Client', 'past_contact', [$pastKey]),
            $this->factNode($sharedOutcomeKey, 'Status: Engaged', 'engaged', [$coldKey, $pastKey]),
            $this->mechanismNode($coldKey),
            $this->mechanismNode($pastKey),
        ];
        $edges = [
            ...$this->edges($segments[0]),
            ...$this->edges($segments[1]),
        ];

        $map = app(ProcessHighwayMapBuilder::class)->build(
            segments: $segments,
            nodes: $nodes,
            edges: $edges,
            subjects: $this->subjects([$coldKey, $pastKey]),
        );

        $this->assertSame(2, $map['highway_count']);

        $coldHighway = collect($map['highways'])->first(
            fn (array $highway): bool => in_array($coldKey, $highway['segment_keys'], true),
        );
        $pastHighway = collect($map['highways'])->first(
            fn (array $highway): bool => in_array($pastKey, $highway['segment_keys'], true),
        );

        $this->assertNotNull($coldHighway);
        $this->assertNotNull($pastHighway);
        $this->assertSame([$coldKey], $coldHighway['segment_keys']);
        $this->assertSame([$pastKey], $pastHighway['segment_keys']);
        $this->assertSame(['status' => ['prospect_nurture']], $coldHighway['qualifiers']);
        $this->assertSame(['status' => ['past_contact']], $pastHighway['qualifiers']);
        $this->assertSame([], $coldHighway['segments'][0]['journey_nodes']);
        $this->assertSame(
            $sharedOutcomeKey,
            $coldHighway['segments'][0]['mechanism_outcomes'][0]['node']['key'],
        );

        $statusFilter = collect($map['qualifier_filters'])->firstWhere('key', 'status');

        $this->assertNotNull($statusFilter);
        $this->assertEqualsCanonicalizing(
            ['prospect_nurture', 'past_contact'],
            collect($statusFilter['options'])->pluck('value')->all(),
        );
        $this->assertFalse(
            collect($statusFilter['options'])->contains('value', 'engaged'),
        );
    }

    public function test_a_non_fact_handoff_connects_a_producer_to_its_consumer(): void
    {
        $campaignKey = 'campaigns:campaign:past_client';
        $routeKey = 'flow_routes:route:past_client_reply';
        $statusKey = 'workflow:status:past_contact';
        $replyProfileKey = 'inbound_messaging:reply_profile:past_client_nurture';
        $completedKey = $routeKey.':completed';
        $campaign = $this->segment($campaignKey, $statusKey, $replyProfileKey);
        $campaign['edge_keys'] = [
            $campaignKey.':edge:start',
            $campaignKey.':edge:handoff',
        ];
        $route = $this->segment($routeKey, $replyProfileKey, $completedKey, 'flow_routes');
        $nodes = [
            $this->factNode($statusKey, 'Status: Past Client', 'past_contact', [$campaignKey]),
            [
                ...$this->baseNode(
                    key: $replyProfileKey,
                    label: 'Reply to Past Client messages',
                    ownerKey: 'inbound_messaging',
                    segmentKeys: [$campaignKey, $routeKey],
                ),
                'role' => 'trigger',
                'reference_only' => true,
            ],
            $this->mechanismNode($campaignKey),
            $this->mechanismNode($routeKey, 'flow_routes'),
            [
                ...$this->baseNode($completedKey, 'Completed', 'flow_routes', [$routeKey]),
                'role' => 'exit',
            ],
        ];
        $edges = [
            $this->edge($campaignKey.':edge:start', $statusKey, $campaignKey, 'starts', $campaignKey),
            $this->edge($campaignKey.':edge:handoff', $campaignKey, $replyProfileKey, 'branch', $campaignKey),
            ...$this->edges($route),
        ];

        $map = app(ProcessHighwayMapBuilder::class)->build(
            segments: [$campaign, $route],
            nodes: $nodes,
            edges: $edges,
            subjects: $this->subjects([$campaignKey, $routeKey]),
        );

        $this->assertSame(1, $map['highway_count']);
        $this->assertEqualsCanonicalizing(
            [$campaignKey, $routeKey],
            $map['highways'][0]['segment_keys'],
        );
        $this->assertSame([$statusKey], $map['highways'][0]['entry_node_keys']);
        $this->assertSame(['status' => ['past_contact']], $map['highways'][0]['qualifiers']);
    }

    public function test_shared_downstream_route_can_appear_in_two_highways_without_merging_their_entrances(): void
    {
        $attendedCampaignKey = 'campaigns:campaign:webinar_attended';
        $missedCampaignKey = 'campaigns:campaign:webinar_missed';
        $routeKey = 'flow_routes:route:webinar_reply';
        $attendedEntryKey = 'webinars:outcome:attended';
        $missedEntryKey = 'webinars:outcome:missed';
        $replyProfileKey = 'inbound_messaging:reply_profile:webinar_homebuyer';
        $completedKey = $routeKey.':completed';
        $attendedCampaign = $this->segment($attendedCampaignKey, $attendedEntryKey, $replyProfileKey);
        $missedCampaign = $this->segment($missedCampaignKey, $missedEntryKey, $replyProfileKey);
        $route = $this->segment($routeKey, $replyProfileKey, $completedKey, 'flow_routes');

        $attendedCampaign['edge_keys'] = [
            $attendedCampaignKey.':edge:start',
            $attendedCampaignKey.':edge:handoff',
        ];
        $missedCampaign['edge_keys'] = [
            $missedCampaignKey.':edge:start',
            $missedCampaignKey.':edge:handoff',
        ];

        $nodes = [
            $this->criterionNode($attendedEntryKey, 'Webinar outcome: Attended', 'webinar_outcome', 'attended', [$attendedCampaignKey]),
            $this->criterionNode($missedEntryKey, 'Webinar outcome: Missed', 'webinar_outcome', 'missed', [$missedCampaignKey]),
            [
                ...$this->baseNode($replyProfileKey, 'Reply to Webinar messages', 'inbound_messaging', [
                    $attendedCampaignKey,
                    $missedCampaignKey,
                    $routeKey,
                ]),
                'role' => 'trigger',
                'reference_only' => true,
            ],
            $this->mechanismNode($attendedCampaignKey),
            $this->mechanismNode($missedCampaignKey),
            $this->mechanismNode($routeKey, 'flow_routes'),
            [
                ...$this->baseNode($completedKey, 'Completed', 'flow_routes', [$routeKey]),
                'role' => 'exit',
            ],
        ];
        $edges = [
            $this->edge($attendedCampaignKey.':edge:start', $attendedEntryKey, $attendedCampaignKey, 'starts', $attendedCampaignKey),
            $this->edge($attendedCampaignKey.':edge:handoff', $attendedCampaignKey, $replyProfileKey, 'branch', $attendedCampaignKey),
            $this->edge($missedCampaignKey.':edge:start', $missedEntryKey, $missedCampaignKey, 'starts', $missedCampaignKey),
            $this->edge($missedCampaignKey.':edge:handoff', $missedCampaignKey, $replyProfileKey, 'branch', $missedCampaignKey),
            ...$this->edges($route),
        ];

        $map = app(ProcessHighwayMapBuilder::class)->build(
            segments: [$attendedCampaign, $missedCampaign, $route],
            nodes: $nodes,
            edges: $edges,
            subjects: $this->subjects([$attendedCampaignKey, $missedCampaignKey, $routeKey]),
        );

        $this->assertSame(2, $map['highway_count']);
        $attendedHighway = collect($map['highways'])->first(
            fn (array $highway): bool => in_array($attendedCampaignKey, $highway['root_segment_keys'], true),
        );
        $missedHighway = collect($map['highways'])->first(
            fn (array $highway): bool => in_array($missedCampaignKey, $highway['root_segment_keys'], true),
        );

        $this->assertNotNull($attendedHighway);
        $this->assertNotNull($missedHighway);
        $this->assertEqualsCanonicalizing([$attendedCampaignKey, $routeKey], $attendedHighway['segment_keys']);
        $this->assertEqualsCanonicalizing([$missedCampaignKey, $routeKey], $missedHighway['segment_keys']);
        $this->assertSame([$attendedEntryKey], $attendedHighway['entry_node_keys']);
        $this->assertSame([$missedEntryKey], $missedHighway['entry_node_keys']);
        $this->assertSame('attended', $attendedHighway['entry_requirements'][0]['values'][0]['value']);
        $this->assertSame('missed', $missedHighway['entry_requirements'][0]['values'][0]['value']);
    }

    /** @return array<string, mixed> */
    private function segment(
        string $key,
        string $entryKey,
        string $outcomeKey,
        string $sourceKey = 'campaigns',
    ): array {
        return [
            'source_key' => $sourceKey,
            'key' => $key,
            'name' => str($key)->afterLast(':')->headline()->toString(),
            'description' => 'Fixture process.',
            'subject_key' => 'contacts',
            'lane_key' => 'contacts:standard',
            'mechanism_node_key' => $key,
            'node_keys' => [$entryKey, $key, $outcomeKey],
            'edge_keys' => [$key.':edge:start', $key.':edge:outcome'],
            'entry_node_keys' => [$entryKey],
            'exit_node_keys' => [$outcomeKey],
            'state' => 'active',
            'state_label' => 'Active',
            'entry_summary' => null,
            'sort_order' => $sourceKey === 'campaigns' ? 100 : 200,
            'details' => [],
            'authority' => $this->authority($sourceKey),
            'attributes' => [],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function edges(array $segment): array
    {
        return [
            $this->edge(
                key: $segment['key'].':edge:start',
                from: $segment['entry_node_keys'][0],
                to: $segment['key'],
                role: 'starts',
                segmentKey: $segment['key'],
            ),
            $this->edge(
                key: $segment['key'].':edge:outcome',
                from: $segment['key'],
                to: $segment['exit_node_keys'][0],
                role: str_starts_with($segment['exit_node_keys'][0], 'inbound_messaging:')
                    ? 'branch'
                    : ($segment['source_key'] === 'flow_routes' ? 'exits' : 'consequence'),
                segmentKey: $segment['key'],
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function edge(
        string $key,
        string $from,
        string $to,
        string $role,
        string $segmentKey,
    ): array {
        return [
            'key' => $key,
            'from_node_key' => $from,
            'to_node_key' => $to,
            'role' => $role,
            'label' => 'Fixture transition',
            'sort_order' => 10,
            'authority' => $this->authority(str_starts_with($segmentKey, 'flow_routes:') ? 'flow_routes' : 'campaigns'),
            'attributes' => [],
            'segment_key' => $segmentKey,
        ];
    }

    /** @return array<string, mixed> */
    private function factNode(string $key, string $label, string $value, array $segmentKeys): array
    {
        return $this->criterionNode($key, $label, 'status', $value, $segmentKeys);
    }

    /** @return array<string, mixed> */
    private function criterionNode(
        string $key,
        string $label,
        string $criterionKey,
        string $value,
        array $segmentKeys,
    ): array {
        return [
            ...$this->baseNode($key, $label, 'workflow', $segmentKeys),
            'role' => 'qualifier',
            'reference_only' => true,
            'attributes' => [
                'criterion_key' => $criterionKey,
                'value' => $value,
                'value_label' => str($label)->after(': ')->toString(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function mechanismNode(string $key, string $ownerKey = 'campaigns'): array
    {
        return $this->baseNode(
            key: $key,
            label: str($key)->afterLast(':')->headline()->toString(),
            ownerKey: $ownerKey,
            segmentKeys: [$key],
        );
    }

    /** @return array<string, mixed> */
    private function baseNode(string $key, string $label, string $ownerKey, array $segmentKeys): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => null,
            'detail' => null,
            'role' => 'process',
            'state' => 'configured',
            'state_label' => 'Configured',
            'sort_order' => 100,
            'reference_only' => false,
            'authority' => $this->authority($ownerKey),
            'attributes' => [],
            'segment_keys' => $segmentKeys,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function subjects(array $segmentKeys): array
    {
        return [[
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
                'segment_keys' => $segmentKeys,
            ]],
        ]];
    }

    /** @return array<string, mixed> */
    private function authority(string $ownerKey): array
    {
        return [
            'owner_key' => $ownerKey,
            'owner_label' => str($ownerKey)->headline()->toString(),
            'tone' => 'slate',
            'editable' => true,
            'edit_targets' => [[
                'mode' => 'link',
                'owner_key' => $ownerKey,
                'owner_label' => str($ownerKey)->headline()->toString(),
                'label' => 'Open owner',
                'url' => '/fixture',
                'method' => 'GET',
                'capability' => null,
                'resource' => ['type' => 'fixture', 'key' => 'fixture', 'id' => 1],
                'container' => null,
            ]],
        ];
    }
}
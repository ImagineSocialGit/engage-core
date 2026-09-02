<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Services\ProcessHighway\CampaignsProcessHighwayContributor;
use App\Support\ProcessHighway\ProcessHighwayGraphComposer;
use App\Support\ProcessHighway\ProcessHighwaySemanticKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CampaignProcessHighwayContributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_contributor_exposes_compound_eligibility_as_owned_graph_nodes_without_mutating_campaign(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'campaigns',
        ])));

        DB::table('contact_statuses')->insert([
            'key' => 'past_contact',
            'name' => 'Past Client',
            'is_core' => false,
            'is_active' => true,
            'is_customized' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $campaign = Campaign::factory()->create([
            'key' => 'past_client_nurture_test',
            'name' => 'Fixture Campaign',
            'status' => Campaign::STATUS_ACTIVE,
            'eligibility_filter' => [
                'status' => ['past_contact'],
                'tag' => ['VIP'],
            ],
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
            'reentry_policy' => Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN,
            'ineligible_behavior' => Campaign::INELIGIBLE_CANCEL,
        ]);

        $graph = app(ProcessHighwayGraphComposer::class)->compose([
            app(CampaignsProcessHighwayContributor::class),
        ]);
        $processKey = ProcessHighwaySemanticKey::campaign('past_client_nurture_test');
        $segment = collect($graph['segments'])->firstWhere('key', $processKey);

        $this->assertNotNull($segment);
        $this->assertSame('campaigns', $segment['source_key']);
        $this->assertSame('contacts:standard', $segment['lane_key']);
        $this->assertSame('active', $segment['state']);
        $this->assertSame($processKey, $segment['mechanism_node_key']);
        $this->assertSame(
            Campaign::ENROLLMENT_MODE_AUTOMATIC,
            $segment['attributes']['enrollment_mode'],
        );
        $this->assertSame(
            Campaign::REENTRY_WHEN_ELIGIBLE_AGAIN,
            $segment['attributes']['reentry_policy'],
        );
        $this->assertSame(
            Campaign::INELIGIBLE_CANCEL,
            $segment['attributes']['ineligible_behavior'],
        );
        $this->assertEqualsCanonicalizing([
            'status' => ['past_contact'],
            'tag' => ['VIP'],
        ], $segment['attributes']['eligibility_filter']);

        $nodes = collect($graph['nodes'])->keyBy('key');
        $statusNode = $nodes[ProcessHighwaySemanticKey::status('past_contact')];
        $tagNode = $nodes[ProcessHighwaySemanticKey::tag('VIP')];
        $campaignNode = $nodes[$processKey];
        $completionNode = $nodes[$processKey.':exit:completed'];
        $ineligibleNode = $nodes[$processKey.':consequence:ineligible'];

        $this->assertSame('workflow', $statusNode['authority']['owner_key']);
        $this->assertSame('amber', $statusNode['authority']['tone']);
        $this->assertSame('core', $tagNode['authority']['owner_key']);
        $this->assertSame('slate', $tagNode['authority']['tone']);
        $this->assertSame('campaigns', $campaignNode['authority']['owner_key']);
        $this->assertSame('rose', $campaignNode['authority']['tone']);
        $this->assertSame('no_reply', $completionNode['attributes']['completion_reason']);
        $this->assertFalse($completionNode['attributes']['contact_status_changes']);
        $this->assertSame('hidden', $ineligibleNode['attributes']['highway_visibility']);
        $this->assertSame(
            'inline',
            $statusNode['authority']['edit_targets'][0]['mode'],
        );
        $this->assertSame(
            'campaigns.eligibility.update',
            $statusNode['authority']['edit_targets'][0]['capability'],
        );
        $statusNavigationTarget = collect($statusNode['authority']['edit_targets'])
            ->firstWhere('mode', 'link');

        $this->assertNotNull($statusNavigationTarget);
        $this->assertSame(
            route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'start',
            ]),
            $statusNavigationTarget['url'],
        );

        $journeyNode = $nodes[$processKey.':journey'];
        $journeyNavigationTarget = collect($journeyNode['authority']['edit_targets'])
            ->firstWhere('mode', 'link');

        $this->assertNotNull($journeyNavigationTarget);
        $this->assertSame(
            route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'messages',
            ]),
            $journeyNavigationTarget['url'],
        );

        $edges = collect($graph['edges'])
            ->where('segment_key', $processKey);
        $eligibilityGatewayKey = $processKey.':eligibility';

        $this->assertTrue($edges->contains(
            fn (array $edge): bool => $edge['from_node_key'] === ProcessHighwaySemanticKey::status('past_contact')
                && $edge['to_node_key'] === $eligibilityGatewayKey
                && $edge['role'] === 'requires',
        ));

        $this->assertSame(1, $graph['highway_count']);
        $businessHighway = $graph['highways'][0];
        $this->assertSame('contacts:standard', $businessHighway['lane_key']);
        $this->assertEqualsCanonicalizing([
            'status' => ['past_contact'],
            'tag' => ['VIP'],
        ], $businessHighway['qualifiers']);
        $this->assertSame('all', $businessHighway['entry_operator']);
        $this->assertEqualsCanonicalizing(
            ['status', 'tag'],
            collect($businessHighway['entry_requirements'])->pluck('criterion_key')->all(),
        );
        $this->assertCount(2, $businessHighway['entry_nodes']);
        $this->assertTrue(collect($businessHighway['entry_nodes'])->every(
            fn (array $node): bool => is_array($node['navigation_target']),
        ));
        $this->assertTrue($edges->contains(
            fn (array $edge): bool => $edge['from_node_key'] === ProcessHighwaySemanticKey::tag('VIP')
                && $edge['to_node_key'] === $eligibilityGatewayKey
                && $edge['role'] === 'requires',
        ));
        $this->assertTrue($edges->contains(
            fn (array $edge): bool => $edge['from_node_key'] === $eligibilityGatewayKey
                && $edge['to_node_key'] === $processKey
                && $edge['role'] === 'starts',
        ));
        $this->assertTrue($edges->contains(
            fn (array $edge): bool => $edge['role'] === 'starts'
                && $edge['from_node_key'] === $processKey.':consequence:eligible-again'
                && $edge['to_node_key'] === $eligibilityGatewayKey,
        ));
        $this->assertTrue($edges->contains(
            fn (array $edge): bool => $edge['key'] === $processKey.':edge:completed'
                && $edge['label'] === 'No reply',
        ));
        $this->assertTrue($edges->contains(
            fn (array $edge): bool => $edge['key'] === $processKey.':edge:ineligible'
                && ($edge['attributes']['highway_visibility'] ?? null) === 'hidden',
        ));
        $this->assertSame(
            'hidden',
            $nodes[$processKey.':consequence:eligible-again']['attributes']['highway_visibility'],
        );
        $this->assertTrue($edges->contains(
            fn (array $edge): bool => $edge['from_node_key'] === $processKey.':consequence:eligible-again'
                && ($edge['attributes']['highway_visibility'] ?? null) === 'hidden',
        ));
        $presentedCampaign = $businessHighway['segments'][0];

        $this->assertSame([], $presentedCampaign['additional_outcome_groups']);
        $this->assertSame(
            [$processKey.':exit:completed'],
            collect($presentedCampaign['journey_nodes'])
                ->flatMap(fn (array $node): array => $node['outcomes'] ?? [])
                ->pluck('node.key')
                ->values()
                ->all(),
        );

        $campaign->refresh();

        $this->assertSame(Campaign::STATUS_ACTIVE, $campaign->status);
        $this->assertEqualsCanonicalizing([
            'status' => ['past_contact'],
            'tag' => ['VIP'],
        ], $campaign->eligibility_filter);
    }

    public function test_manual_campaign_keeps_explicit_entry_even_when_target_criteria_are_saved(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'campaigns',
        ])));

        Campaign::factory()->create([
            'key' => 'manual_targeted_campaign',
            'status' => Campaign::STATUS_INACTIVE,
            'eligibility_filter' => [
                'tag' => ['VIP'],
            ],
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_MANUAL,
        ]);

        $graph = app(ProcessHighwayGraphComposer::class)->compose([
            app(CampaignsProcessHighwayContributor::class),
        ]);
        $processKey = ProcessHighwaySemanticKey::campaign('manual_targeted_campaign');
        $segment = collect($graph['segments'])->firstWhere('key', $processKey);

        $this->assertNotNull($segment);
        $this->assertSame('off', $segment['state']);
        $this->assertSame(
            Campaign::ENROLLMENT_MODE_MANUAL,
            $segment['attributes']['enrollment_mode'],
        );
        $this->assertEqualsCanonicalizing(
            ['tag' => ['VIP']],
            $segment['attributes']['eligibility_filter'],
        );
        $this->assertSame(
            [$processKey.':entry:manual'],
            $segment['entry_node_keys'],
        );

        $nodes = collect($graph['nodes'])->keyBy('key');

        $this->assertSame(
            'trigger',
            $nodes[$processKey.':entry:manual']['role'],
        );
        $this->assertArrayNotHasKey(
            ProcessHighwaySemanticKey::tag('VIP'),
            $nodes->all(),
        );
    }

    public function test_relationship_criteria_place_the_process_in_a_relationship_scoped_lane(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'campaigns',
            'relationships',
        ])));

        Campaign::factory()->create([
            'key' => 'realtor_relationship_nurture',
            'name' => 'Realtor relationship nurture',
            'status' => Campaign::STATUS_ACTIVE,
            'eligibility_filter' => [
                'relationship' => ['realtor:target_agent'],
            ],
            'enrollment_mode' => Campaign::ENROLLMENT_MODE_AUTOMATIC,
        ]);

        $graph = app(ProcessHighwayGraphComposer::class)->compose([
            app(CampaignsProcessHighwayContributor::class),
        ]);
        $processKey = ProcessHighwaySemanticKey::campaign('realtor_relationship_nurture');
        $segment = collect($graph['segments'])->firstWhere('key', $processKey);
        $relationshipNode = collect($graph['nodes'])->firstWhere(
            'key',
            ProcessHighwaySemanticKey::relationship('realtor', 'target_agent'),
        );

        $this->assertNotNull($segment);
        $this->assertSame('contacts:relationship:realtor', $segment['lane_key']);
        $this->assertNotNull($relationshipNode);
        $this->assertSame('relationships', $relationshipNode['authority']['owner_key']);
        $this->assertSame('cyan', $relationshipNode['authority']['tone']);

        $subject = collect($graph['subjects'])->firstWhere('key', 'contacts');
        $lane = collect($subject['lanes'])->firstWhere(
            'key',
            'contacts:relationship:realtor',
        );

        $this->assertNotNull($lane);
        $this->assertSame('relationship', $lane['scope']);
        $this->assertSame('realtor', $lane['relationship_key']);
        $this->assertSame(1, $lane['highway_count']);
        $this->assertSame(
            'contacts:relationship:realtor',
            $graph['highways'][0]['lane_key'],
        );
    }
}
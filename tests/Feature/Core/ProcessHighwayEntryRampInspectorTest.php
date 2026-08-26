<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Modules\Workflow\Services\ProcessHighway\WorkflowProcessHighwayEntryRampContributor;
use App\Support\ProcessHighway\ProcessHighwayEntryRampInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProcessHighwayEntryRampInspectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_ramp_reports_current_contacts_and_configured_application_paths(): void
    {
        $contacts = Contact::factory()->count(3)->create();

        ContactTag::query()->create([
            'contact_id' => $contacts[0]->getKey(),
            'tag' => 'Old Lead',
        ]);
        ContactTag::query()->create([
            'contact_id' => $contacts[1]->getKey(),
            'tag' => 'Old Lead',
        ]);
        ContactTag::query()->create([
            'contact_id' => $contacts[2]->getKey(),
            'tag' => 'Different Tag',
        ]);

        $nodeKey = 'core:contact_tag:present:Old%20Lead';
        $node = [
            'key' => $nodeKey,
            'label' => 'Tag: Old Lead',
            'attributes' => [
                'criterion_key' => 'tag',
                'value' => 'Old Lead',
                'value_label' => 'Old Lead',
            ],
        ];
        $routeKey = 'flow_routes:route:mark_old_lead';
        $graph = [
            'segments' => [[
                'key' => $routeKey,
                'source_key' => 'flow_routes',
                'name' => 'Mark old lead',
                'authority' => [
                    'edit_targets' => [[
                        'mode' => 'link',
                        'method' => 'GET',
                        'url' => '/routes/mark-old-lead',
                    ]],
                ],
            ]],
            'nodes' => [$node],
            'edges' => [[
                'key' => $routeKey.':edge:add-tag',
                'segment_key' => $routeKey,
                'from_node_key' => $routeKey,
                'to_node_key' => $nodeKey,
                'role' => 'consequence',
                'label' => 'Adds tag',
            ]],
            'highways' => [[
                'entry_node_keys' => [$nodeKey],
                'entry_nodes' => [$node],
            ]],
        ];

        $decorated = app(ProcessHighwayEntryRampInspector::class)->decorate($graph);
        $inspection = $decorated['entry_ramp_inspectors'][$nodeKey];

        $this->assertSame(2, $inspection['contact_count']);
        $this->assertEqualsCanonicalizing([
            'core:contact_import',
            'flow_route:'.$routeKey,
        ], collect($inspection['application_sources'])->pluck('key')->all());
        $this->assertSame(
            '/routes/mark-old-lead',
            collect($inspection['application_sources'])
                ->firstWhere('key', 'flow_route:'.$routeKey)['url'],
        );
        $this->assertSame(
            2,
            $decorated['highways'][0]['entry_nodes'][0]['inspector']['contact_count'],
        );
    }

    public function test_status_ramp_reports_contacts_with_the_current_status(): void
    {
        $contacts = Contact::factory()->count(3)->create();
        $statusId = DB::table('contact_statuses')->insertGetId([
            'key' => 'past_contact',
            'name' => 'Past Client',
            'is_core' => false,
            'is_active' => true,
            'is_customized' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contacts[0]->getKey(),
            'contact_status_id' => $statusId,
        ]);
        ContactWorkflowProfile::query()->create([
            'contact_id' => $contacts[1]->getKey(),
            'contact_status_id' => $statusId,
        ]);

        $inspection = app(WorkflowProcessHighwayEntryRampContributor::class)->inspect(
            value: 'past_contact',
            node: [],
        );

        $this->assertSame(2, $inspection['contact_count']);
        $this->assertEqualsCanonicalizing([
            'workflow:contact_editor',
            'workflow:contact_import',
        ], collect($inspection['application_sources'])->pluck('key')->all());
    }
    public function test_status_ramp_summarizes_direct_partial_and_downstream_process_effects(): void
    {
        $statusId = DB::table('contact_statuses')->insertGetId([
            'key' => 'past_contact',
            'name' => 'Past Client',
            'is_core' => false,
            'is_active' => true,
            'is_customized' => false,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $contact = Contact::factory()->create();

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $statusId,
        ]);

        $statusNodeKey = 'workflow:status:past_contact';
        $tagNodeKey = 'core:contact_tag:present:VIP';
        $statusNode = [
            'key' => $statusNodeKey,
            'label' => 'Status: Past Client',
            'attributes' => [
                'criterion_key' => 'status',
                'value' => 'past_contact',
                'value_label' => 'Past Client',
            ],
        ];
        $tagNode = [
            'key' => $tagNodeKey,
            'label' => 'Tag: VIP',
            'attributes' => [
                'criterion_key' => 'tag',
                'value' => 'VIP',
                'value_label' => 'VIP',
            ],
        ];

        $graph = [
            'segments' => [],
            'nodes' => [$statusNode, $tagNode],
            'edges' => [],
            'highways' => [
                [
                    'key' => 'contacts:standard:highway:direct',
                    'name' => 'Past Client birthday follow-up',
                    'state' => 'active',
                    'state_label' => 'Active',
                    'lane_scope' => 'standard',
                    'relationship_key' => null,
                    'relationship_label' => null,
                    'entry_node_keys' => [$statusNodeKey],
                    'entry_nodes' => [$statusNode],
                    'entry_requirements' => [[
                        'criterion_key' => 'status',
                        'criterion_label' => 'Status',
                        'values' => [[
                            'value' => 'past_contact',
                            'label' => 'Past Client',
                            'node_key' => $statusNodeKey,
                        ]],
                    ]],
                    'segments' => [[
                        'key' => 'flow_routes:route:past_client_follow_up',
                        'source_key' => 'flow_routes',
                        'name' => 'Past Client follow-up',
                        'state' => 'active',
                        'state_label' => 'Active',
                        'authority' => [
                            'owner_key' => 'flow_routes',
                            'owner_label' => 'Routes',
                        ],
                        'navigation_target' => [
                            'url' => '/routes/past-client-follow-up',
                        ],
                        'journey_nodes' => [[
                            'key' => 'flow_routes:route:past_client_follow_up:point:create_task',
                            'label' => 'Create follow-up task',
                            'detail' => 'Create the configured task.',
                            'navigation_target' => [
                                'url' => '/routes/past-client-follow-up',
                            ],
                            'outcomes' => [],
                        ]],
                        'mechanism_outcomes' => [],
                        'additional_outcome_groups' => [],
                        'branch_edges' => [],
                        'supporting_acknowledgements' => [],
                        'attributes' => [],
                    ]],
                ],
                [
                    'key' => 'contacts:standard:highway:partial',
                    'name' => 'VIP Past Client nurture',
                    'state' => 'active',
                    'state_label' => 'Active',
                    'lane_scope' => 'standard',
                    'relationship_key' => null,
                    'relationship_label' => null,
                    'entry_node_keys' => [$statusNodeKey, $tagNodeKey],
                    'entry_nodes' => [$statusNode, $tagNode],
                    'entry_requirements' => [
                        [
                            'criterion_key' => 'status',
                            'criterion_label' => 'Status',
                            'values' => [[
                                'value' => 'past_contact',
                                'label' => 'Past Client',
                                'node_key' => $statusNodeKey,
                            ]],
                        ],
                        [
                            'criterion_key' => 'tag',
                            'criterion_label' => 'Tag',
                            'values' => [[
                                'value' => 'VIP',
                                'label' => 'VIP',
                                'node_key' => $tagNodeKey,
                            ]],
                        ],
                    ],
                    'segments' => [[
                        'key' => 'campaigns:campaign:vip_past_client',
                        'source_key' => 'campaigns',
                        'name' => 'VIP Past Client nurture',
                        'state' => 'active',
                        'state_label' => 'Active',
                        'authority' => [
                            'owner_key' => 'campaigns',
                            'owner_label' => 'Campaigns',
                        ],
                        'navigation_target' => [
                            'url' => '/campaigns/vip-past-client',
                        ],
                        'journey_nodes' => [[
                            'key' => 'campaigns:campaign:vip_past_client:journey',
                            'label' => 'Message journey',
                            'detail' => 'Email and SMS follow-up.',
                            'navigation_target' => [
                                'url' => '/campaigns/vip-past-client?panel=messages',
                            ],
                            'outcomes' => [],
                        ]],
                        'mechanism_outcomes' => [[
                            'edge' => [
                                'key' => 'campaigns:campaign:vip_past_client:edge:engaged',
                                'label' => 'Can mark',
                            ],
                            'node' => [
                                'key' => 'workflow:status:engaged',
                                'label' => 'Status: Engaged',
                                'navigation_target' => [
                                    'url' => '/campaigns/vip-past-client?panel=review',
                                ],
                            ],
                        ]],
                        'additional_outcome_groups' => [],
                        'branch_edges' => [[
                            'key' => 'campaigns:campaign:vip_past_client:edge:reply',
                            'label' => 'If the contact replies',
                            'to_label' => 'Mortgage lead reply profile',
                            'navigation_target' => [
                                'url' => '/reply-profiles/mortgage-lead',
                            ],
                        ]],
                        'supporting_acknowledgements' => [],
                        'attributes' => [],
                    ]],
                ],
            ],
        ];

        $decorated = app(ProcessHighwayEntryRampInspector::class)->decorate($graph);
        $inspection = $decorated['entry_ramp_inspectors'][$statusNodeKey];

        $this->assertSame(2, $inspection['process_count']);
        $this->assertSame(1, $inspection['exact_process_count']);
        $this->assertSame(1, $inspection['partial_process_count']);

        $direct = collect($inspection['processes'])->firstWhere(
            'key',
            'contacts:standard:highway:direct',
        );
        $partial = collect($inspection['processes'])->firstWhere(
            'key',
            'contacts:standard:highway:partial',
        );

        $this->assertSame('exact', $direct['match']);
        $this->assertEquals([], $direct['remaining_requirements']);
        $this->assertSame(
            'Create follow-up task',
            $direct['segments'][0]['effects'][0]['label'],
        );

        $this->assertSame('partial', $partial['match']);
        $this->assertEquals([
            [
                'criterion_key' => 'tag',
                'criterion_label' => 'Tag',
                'values' => ['VIP'],
            ],
        ], $partial['remaining_requirements']);

        $effectLabels = collect($partial['segments'][0]['effects'])
            ->pluck('label')
            ->all();

        $this->assertContains('Message journey', $effectLabels);
        $this->assertContains('Can mark → Status: Engaged', $effectLabels);
        $this->assertContains(
            'If the contact replies → Mortgage lead reply profile',
            $effectLabels,
        );
        $this->assertSame(
            '/campaigns/vip-past-client',
            $partial['segments'][0]['navigation_target']['url'],
        );
    }

}
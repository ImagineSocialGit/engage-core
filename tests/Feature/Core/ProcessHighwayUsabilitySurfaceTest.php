<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

class ProcessHighwayUsabilitySurfaceTest extends TestCase
{
    public function test_surface_requires_an_audience_and_progressively_discloses_one_business_process(): void
    {
        $view = $this->view('crm.process-highway.index', [
            'title' => 'Process Highway',
            'heading' => 'Process Highway',
            'subheading' => 'Fixture subheading.',
            'highway' => $this->highway(),
            'initialQualifierSelection' => [
                'status' => 'past_contact',
            ],
            'initialHighwayKey' => 'contacts:standard:highway:fixture',
            'initialSubjectKey' => 'contacts',
            'initialContactMode' => 'standard',
            'initialRelationship' => null,
        ]);

        $view
            ->assertSee('data-process-highway', false)
            ->assertSee('data-process-highway-audience', false)
            ->assertSee('data-process-highway-contact-mode', false)
            ->assertSee('data-process-highway-relationship-type', false)
            ->assertSee('data-process-highway-primary-filters', false)
            ->assertSee('data-process-highway-qualifier="status"', false)
            ->assertSee('data-process-highway-awaiting-filter', false)
            ->assertSee('data-process-highway-results', false)
            ->assertSee('data-process-highway-exact-results', false)
            ->assertSee('data-process-highway-no-exact-match', false)
            ->assertSee('data-process-highway-partial-results', false)
            ->assertSee('data-process-highway-result', false)
            ->assertSee('data-process-highway-match="exact"', false)
            ->assertSee('data-process-highway-match="partial"', false)
            ->assertSee('data-process-highway-toggle="contacts:standard:highway:fixture"', false)
            ->assertSee('data-process-highway-details="contacts:standard:highway:fixture"', false)
            ->assertSee('data-process-highway-entry-expression="contacts:standard:highway:fixture"', false)
            ->assertSee('data-process-highway-entry-requirement="status"', false)
            ->assertSee('data-entry-ramp-inspector="workflow:status:past_contact"', false)
            ->assertSee('data-process-highway-fact-inspectors', false)
            ->assertSee('data-process-highway-fact-inspector', false)
            ->assertSee('data-process-highway-automatic-sources', false)
            ->assertSee('data-process-highway-automatic-source', false)
            ->assertSee('data-process-highway-source-owner-link', false)
            ->assertSee('data-process-highway-source-highway-target', false)
            ->assertSee('data-process-highway-other-sources', false)
            ->assertSee('data-process-highway-missing-requirements="contacts:standard:highway:fixture"', false)
            ->assertSee('data-process-highway-segment-group="programs"', false)
            ->assertSee('data-process-highway-mechanism="campaigns"', false)
            ->assertSee('data-process-highway-outcome="workflow:status:engaged"', false)
            ->assertSee('id="process-highway-exit-fixture"', false)
            ->assertSee('data-process-highway-exit-edge="campaigns:campaign:past_client:edge:engaged"', false)
            ->assertSee('data-process-highway-exit-highway="contacts:standard:highway:fixture"', false)
            ->assertSee('data-process-highway-fact-target="status:engaged"', false)
            ->assertSee('href="/process-highway?status=engaged"', false)
            ->assertDontSee('data-process-highway-impact-processes', false)
            ->assertDontSee('data-process-highway-entry-actions', false)
            ->assertDontSee('data-process-highway-mechanism="workflow"', false);
    }

    /** @return array<string, mixed> */
    private function highway(): array
    {
        $authority = [
            'owner_key' => 'campaigns',
            'owner_label' => 'Campaigns',
            'tone' => 'rose',
            'editable' => true,
            'edit_targets' => [],
        ];
        $navigationTarget = [
            'mode' => 'link',
            'owner_key' => 'campaigns',
            'owner_label' => 'Campaigns',
            'label' => 'Edit Campaign',
            'url' => '/campaigns/1/edit',
            'method' => 'GET',
            'capability' => null,
            'resource' => ['type' => 'campaign', 'key' => 'past_client', 'id' => 1],
            'container' => null,
        ];
        $entryNode = [
            'key' => 'workflow:status:past_contact',
            'label' => 'Status: Past Client',
            'navigation_target' => [
                ...$navigationTarget,
                'owner_key' => 'workflow',
                'owner_label' => 'Workflow',
                'label' => 'Open Contacts',
                'url' => '/contacts',
            ],
            'inspector' => [
                'node_key' => 'workflow:status:past_contact',
                'criterion_key' => 'status',
                'criterion_label' => 'Status',
                'value' => 'past_contact',
                'value_label' => 'Past Client',
                'contact_count' => 4,
                'application_sources' => [
                    [
                        'key' => 'flow_route:flow_routes:route:past_client_reply',
                        'label' => 'Past Client reply',
                        'detail' => 'Changes status to',
                        'url' => '/flow-routes/1',
                        'source_type' => 'automatic',
                        'owner_key' => 'flow_routes',
                        'highway_targets' => [[
                            'highway_key' => 'contacts:standard:highway:fixture',
                            'highway_name' => 'Past Client Nurture',
                            'edge_key' => 'campaigns:campaign:past_client:edge:engaged',
                            'anchor' => 'process-highway-exit-fixture',
                            'url' => '/process-highway?highway=contacts%3Astandard%3Ahighway%3Afixture#process-highway-exit-fixture',
                            'entry_selection' => ['status' => 'past_contact'],
                            'lane_scope' => 'standard',
                        ]],
                    ],
                    [
                        'key' => 'workflow:contact_editor',
                        'label' => 'Contact workspace',
                        'detail' => 'A user can assign this status directly.',
                    ],
                ],
                'process_count' => 1,
                'exact_process_count' => 1,
                'partial_process_count' => 0,
                'processes' => [[
                    'key' => 'contacts:standard:highway:fixture',
                    'name' => 'Past Client Nurture',
                    'match' => 'exact',
                    'match_label' => 'Exact match',
                    'state' => 'active',
                    'state_label' => 'Active',
                    'remaining_requirements' => [],
                    'segments' => [[
                        'key' => 'campaigns:campaign:past_client',
                        'name' => 'Past Client Nurture',
                        'owner_key' => 'campaigns',
                        'owner_label' => 'Campaigns',
                        'state' => 'active',
                        'state_label' => 'Active',
                        'navigation_target' => $navigationTarget,
                        'effects' => [],
                    ]],
                ]],
            ],
        ];
        $outcomeNode = [
            'key' => 'workflow:status:engaged',
            'label' => 'Status: Engaged',
            'navigation_target' => [
                ...$navigationTarget,
                'owner_key' => 'workflow',
                'owner_label' => 'Workflow',
                'label' => 'Open Contacts',
                'url' => '/contacts',
            ],
        ];

        return [
            'highway_count' => 1,
            'subjects' => [[
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
                    'segment_keys' => ['campaigns:campaign:past_client'],
                ]],
            ]],
            'qualifier_filters' => [[
                'key' => 'status',
                'label' => 'Status',
                'priority' => 10,
                'options' => [[
                    'value' => 'past_contact',
                    'label' => 'Past Client',
                    'highway_keys' => ['contacts:standard:highway:fixture'],
                ]],
            ]],
            'entry_ramp_inspectors' => [
                'workflow:status:past_contact' => $entryNode['inspector'],
            ],
            'highways' => [[
                'key' => 'contacts:standard:highway:fixture',
                'name' => 'Past Client Nurture',
                'subject_key' => 'contacts',
                'subject_label' => 'Contacts',
                'lane_key' => 'contacts:standard',
                'lane_label' => 'Standard contacts',
                'lane_scope' => 'standard',
                'relationship_key' => null,
                'state' => 'active',
                'state_label' => 'Active',
                'segment_count' => 1,
                'qualifiers' => ['status' => ['past_contact']],
                'entry_requirements' => [[
                    'criterion_key' => 'status',
                    'criterion_label' => 'Status',
                    'operator' => 'any',
                    'values' => [[
                        'value' => 'past_contact',
                        'label' => 'Past Client',
                        'node_key' => 'workflow:status:past_contact',
                    ]],
                ]],
                'search_text' => 'past client nurture engaged',
                'entry_nodes' => [$entryNode],
                'segments' => [[
                    'key' => 'campaigns:campaign:past_client',
                    'source_key' => 'campaigns',
                    'name' => 'Past Client Nurture',
                    'description' => 'Keeps in touch with past clients.',
                    'state' => 'active',
                    'state_label' => 'Active',
                    'attributes' => [
                        'mechanism_role' => 'eligibility_program',
                    ],
                    'authority' => $authority,
                    'navigation_target' => $navigationTarget,
                    'journey_nodes' => [],
                    'mechanism_outcomes' => [[
                        'edge' => [
                            'key' => 'campaigns:campaign:past_client:edge:engaged',
                            'label' => 'Positive reply can mark',
                        ],
                        'node' => $outcomeNode,
                        'exit_anchor' => 'process-highway-exit-fixture',
                        'fact_target' => [
                            'criterion_key' => 'status',
                            'value' => 'engaged',
                            'label' => 'Engaged',
                            'url' => '/process-highway?status=engaged',
                        ],
                    ]],
                    'additional_outcome_groups' => [],
                ]],
            ]],
        ];
    }
}
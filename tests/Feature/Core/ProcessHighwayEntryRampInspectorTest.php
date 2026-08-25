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
}
<?php

namespace Tests\Feature\Relationships;

use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Modules\Relationships\Services\Contacts\Filters\RelationshipContactFilterCriterion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipContactFilterCriterionTest extends TestCase
{
    use RefreshDatabase;

    public function test_relationship_criterion_can_target_a_relationship_stage_without_conflating_contact_source(): void
    {
        config()->set('relationships.types', [
            'consumer' => [
                'singular' => 'Lead',
                'plural' => 'Leads',
                'visible' => true,
                'sort_order' => 10,
                'stages' => [],
            ],
            'realtor' => [
                'singular' => 'Realtor',
                'plural' => 'Realtors',
                'visible' => true,
                'sort_order' => 20,
                'stages' => [
                    'target_agent' => [
                        'label' => 'Target Agent',
                        'sort_order' => 10,
                        'active' => true,
                    ],
                ],
            ],
        ]);

        $agent = Contact::factory()->create(['source' => 'Database']);
        $realtorComLead = Contact::factory()->create(['source' => 'Realtor.com']);

        ContactRelationship::query()->create([
            'contact_id' => $agent->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'target_agent',
            'is_active' => true,
            'started_at' => now(),
        ]);

        ContactRelationship::query()->create([
            'contact_id' => $realtorComLead->id,
            'relationship_key' => 'consumer',
            'stage_key' => null,
            'is_active' => true,
            'started_at' => now(),
        ]);

        $criterion = app(RelationshipContactFilterCriterion::class);
        $query = Contact::query();
        $criterion->apply($query, ['realtor:target_agent']);

        $this->assertEquals([$agent->id], $query->pluck('id')->all());
        $this->assertNotContains($realtorComLead->id, $query->pluck('id')->all());
    }
}
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

    public function test_relationship_presence_options_distinguish_active_relationships(): void
    {
        $none = Contact::factory()->create();
        $inactive = Contact::factory()->create();
        $active = Contact::factory()->create();
        $other = Contact::factory()->create();

        ContactRelationship::query()->create([
            'contact_id' => $inactive->id,
            'relationship_key' => 'realtor',
            'stage_key' => null,
            'is_active' => false,
            'started_at' => now(),
        ]);
        ContactRelationship::query()->create([
            'contact_id' => $active->id,
            'relationship_key' => 'realtor',
            'stage_key' => null,
            'is_active' => true,
            'started_at' => now(),
        ]);
        ContactRelationship::query()->create([
            'contact_id' => $other->id,
            'relationship_key' => 'consumer',
            'stage_key' => null,
            'is_active' => true,
            'started_at' => now(),
        ]);

        $criterion = app(RelationshipContactFilterCriterion::class);
        $this->assertSame(
            [RelationshipContactFilterCriterion::NO_RELATIONSHIP],
            $criterion->normalize([RelationshipContactFilterCriterion::NO_RELATIONSHIP]),
        );
        $this->assertSame(
            [RelationshipContactFilterCriterion::ANY_RELATIONSHIP],
            $criterion->normalize([RelationshipContactFilterCriterion::ANY_RELATIONSHIP]),
        );

        $without = Contact::query();
        $criterion->apply($without, [RelationshipContactFilterCriterion::NO_RELATIONSHIP]);
        $this->assertEqualsCanonicalizing([$none->id, $inactive->id], $without->pluck('id')->all());

        $with = Contact::query();
        $criterion->apply($with, [RelationshipContactFilterCriterion::ANY_RELATIONSHIP]);
        $this->assertEqualsCanonicalizing([$active->id, $other->id], $with->pluck('id')->all());

        $combined = Contact::query();
        $criterion->apply($combined, [RelationshipContactFilterCriterion::NO_RELATIONSHIP, 'realtor:*']);
        $this->assertEqualsCanonicalizing([$none->id, $inactive->id, $active->id], $combined->pluck('id')->all());
    }

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
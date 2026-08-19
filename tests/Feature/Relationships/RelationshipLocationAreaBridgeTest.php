<?php

namespace Tests\Feature\Relationships;

use App\Modules\Core\Models\Contact;
use App\Modules\Location\Models\LocationArea;
use App\Modules\Location\Models\LocationAreaAssignment;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Support\ModuleIntegrations\RelationshipLocationAreaBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class RelationshipLocationAreaBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_location_can_assign_a_market_to_a_relationship_without_relationships_importing_location(): void
    {
        config()->set('modules.enabled', ['relationships', 'location']);

        $contact = Contact::factory()->create();
        $relationship = ContactRelationship::query()->create([
            'contact_id' => $contact->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'strategic_partner',
            'is_active' => true,
        ]);
        $area = LocationArea::factory()->create([
            'key' => 'tampa',
            'name' => 'Tampa',
            'type' => LocationArea::TYPE_MARKET,
        ]);

        $assignment = app(RelationshipLocationAreaBridge::class)->assign(
            relationship: $relationship,
            area: $area,
            isPrimary: true,
            source: 'relationship_import',
        );

        $this->assertSame($relationship->getMorphClass(), $assignment->subject_type);
        $this->assertSame($relationship->id, $assignment->subject_id);
        $this->assertSame($contact->id, $assignment->contact_id);
        $this->assertTrue($assignment->is_primary);
    }

    public function test_bridge_fails_closed_when_location_is_not_explicitly_enabled(): void
    {
        config()->set('modules.enabled', ['relationships']);

        $contact = Contact::factory()->create();
        $relationship = ContactRelationship::query()->create([
            'contact_id' => $contact->id,
            'relationship_key' => 'realtor',
            'is_active' => true,
        ]);
        $area = LocationArea::factory()->create([
            'key' => 'tampa',
            'name' => 'Tampa',
            'type' => LocationArea::TYPE_MARKET,
        ]);

        $this->expectException(LogicException::class);

        try {
            app(RelationshipLocationAreaBridge::class)->assign(
                relationship: $relationship,
                area: $area,
            );
        } finally {
            $this->assertDatabaseCount('location_area_assignments', 0);
        }
    }
}
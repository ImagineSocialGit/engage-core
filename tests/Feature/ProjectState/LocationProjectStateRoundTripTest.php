<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Core\Models\Contact;
use App\Modules\Location\Actions\AssignSubjectToLocationAreaAction;
use App\Modules\Location\Models\LocationArea;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_location_areas_and_relationship_assignments_round_trip_with_reference_remapping(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'agent@example.test',
        ]);
        $relationship = ContactRelationship::query()->create([
            'contact_id' => $contact->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'strategic_partner',
            'source' => 'agent_database',
            'is_active' => true,
        ]);
        $area = LocationArea::factory()->create([
            'key' => 'fl_space_coast',
            'name' => 'Space Coast',
            'type' => LocationArea::TYPE_MARKET,
            'source' => 'client_config',
        ]);

        $assignment = app(AssignSubjectToLocationAreaAction::class)->handle(
            area: $area,
            subject: $relationship,
            contact: $contact,
            isPrimary: true,
            source: 'agent_import',
        );

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(17, $document['version']);
        $this->assertSame(1, $document['sections']['location']['version']);
        $this->assertCount(1, $document['sections']['location']['tables']['location_areas']);
        $this->assertCount(1, $document['sections']['location']['tables']['location_area_assignments']);

        DB::table('location_area_assignments')->delete();
        DB::table('location_areas')->delete();
        DB::table('contact_relationships')->delete();
        DB::table('contacts')->delete();

        $report = $projectState->validate($document);
        $this->assertTrue($report['valid'], implode(PHP_EOL, $report['errors']));

        $projectState->import($document);

        $importedArea = LocationArea::query()->where('key', 'fl_space_coast')->firstOrFail();
        $importedRelationship = ContactRelationship::query()->where('relationship_key', 'realtor')->firstOrFail();

        $this->assertDatabaseHas('location_area_assignments', [
            'id' => $assignment->id,
            'location_area_id' => $importedArea->id,
            'contact_id' => Contact::query()->where('email', 'agent@example.test')->value('id'),
            'subject_type' => ContactRelationship::class,
            'subject_id' => $importedRelationship->id,
            'role' => 'member',
            'is_primary' => 1,
            'location_id' => null,
        ]);
    }
}
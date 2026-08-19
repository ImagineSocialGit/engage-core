<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RelationshipsProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_relationship_state_round_trips_after_core_and_before_consuming_verticals(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'multi-role@example.test',
        ]);

        $consumer = ContactRelationship::query()->create([
            'contact_id' => $contact->id,
            'relationship_key' => 'consumer',
            'stage_key' => 'past_client',
            'source' => 'database',
            'is_active' => true,
            'started_at' => '2024-06-01 12:00:00',
            'meta' => ['segment' => 'past_client'],
        ]);

        $realtor = ContactRelationship::query()->create([
            'contact_id' => $contact->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'strategic_partner',
            'source' => 'referral',
            'is_active' => true,
            'started_at' => '2026-01-10 09:00:00',
        ]);

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(16, $document['version']);
        $this->assertSame(1, $document['sections']['relationships']['version']);
        $this->assertCount(
            2,
            $document['sections']['relationships']['tables']['contact_relationships'],
        );

        $sectionKeys = array_keys($document['sections']);
        $this->assertSame(
            ['core', 'relationships'],
            array_slice($sectionKeys, 0, 2),
        );

        DB::table('contact_relationships')->delete();
        DB::table('contacts')->delete();

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid'], implode(PHP_EOL, $report['errors']));

        $projectState->import($document);

        $this->assertDatabaseHas('contact_relationships', [
            'id' => $consumer->id,
            'contact_id' => $contact->id,
            'relationship_key' => 'consumer',
            'stage_key' => 'past_client',
            'source' => 'database',
        ]);
        $this->assertDatabaseHas('contact_relationships', [
            'id' => $realtor->id,
            'contact_id' => $contact->id,
            'relationship_key' => 'realtor',
            'stage_key' => 'strategic_partner',
            'source' => 'referral',
        ]);
    }
}
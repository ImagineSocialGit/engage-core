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

        $this->assertSame(
            (int) config('project_state.version'),
            $document['version'],
        );
        $this->assertSame(
            (int) config('project_state.sections.relationships.version'),
            $document['sections']['relationships']['version'],
        );
        $this->assertCount(
            2,
            $document['sections']['relationships']['tables']['contact_relationships'],
        );

        $sectionKeys = array_keys($document['sections']);
        $coreIndex = array_search('core', $sectionKeys, true);
        $relationshipsIndex = array_search('relationships', $sectionKeys, true);

        $this->assertNotFalse($coreIndex);
        $this->assertNotFalse($relationshipsIndex);
        $this->assertTrue(
            $coreIndex < $relationshipsIndex,
            'Core Project State must be imported before Relationships.',
        );

        $mortgageIndex = array_search('mortgage', $sectionKeys, true);

        if ($mortgageIndex !== false) {
            $this->assertTrue(
                $relationshipsIndex < $mortgageIndex,
                'Relationships Project State must be imported before Mortgage.',
            );
        }

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
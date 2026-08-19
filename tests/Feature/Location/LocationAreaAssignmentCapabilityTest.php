<?php

namespace Tests\Feature\Location;

use App\Modules\Core\Models\Contact;
use App\Modules\Location\Actions\AssignSubjectToLocationAreaAction;
use App\Modules\Location\Models\LocationArea;
use App\Modules\Location\Models\LocationAreaAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationAreaAssignmentCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_subject_assignment_is_idempotent_and_primary_switch_is_scoped_to_role(): void
    {
        $contact = Contact::factory()->create();
        $firstArea = LocationArea::factory()->create([
            'key' => 'tampa',
            'name' => 'Tampa',
            'type' => LocationArea::TYPE_MARKET,
        ]);
        $secondArea = LocationArea::factory()->create([
            'key' => 'space_coast',
            'name' => 'Space Coast',
            'type' => LocationArea::TYPE_MARKET,
        ]);

        $action = app(AssignSubjectToLocationAreaAction::class);

        $first = $action->handle(
            area: $firstArea,
            subject: $contact,
            contact: $contact,
            isPrimary: true,
            source: 'test',
        );

        $replayed = $action->handle(
            area: $firstArea,
            subject: $contact,
            contact: $contact,
            isPrimary: true,
            source: 'test',
        );

        $second = $action->handle(
            area: $secondArea,
            subject: $contact,
            contact: $contact,
            isPrimary: true,
            source: 'test',
        );

        $this->assertSame($first->id, $replayed->id);
        $this->assertCount(2, LocationAreaAssignment::query()->get());
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->fresh()->is_primary);
        $this->assertSame($contact->getMorphClass(), $second->subject_type);
        $this->assertSame($contact->id, $second->subject_id);
    }
}
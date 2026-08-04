<?php

namespace Tests\Feature\Events;

use App\Modules\Events\Enums\EventAttendanceMode;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventExternalReference;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EventsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_foundation_tables_have_canonical_columns(): void
    {
        $this->assertTableHasColumns('events', [
            'type_key',
            'title',
            'description',
            'status',
            'attendance_mode',
            'starts_at',
            'ends_at',
            'timezone',
            'announcement_at',
            'venue_name',
            'address_line_1',
            'address_line_2',
            'city',
            'region',
            'postal_code',
            'country',
            'primary_external_reference_id',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('event_external_references', [
            'event_id',
            'provider_key',
            'reference_type',
            'external_id',
            'url',
            'label',
            'deleted_at',
        ]);
    }

    public function test_event_factory_persists_enum_casts_and_historical_location_snapshot(): void
    {
        $event = Event::factory()->upcoming()->create([
            'attendance_mode' => EventAttendanceMode::Hybrid->value,
            'ends_at' => null,
            'venue_name' => 'Civic Hall',
            'city' => 'Chicago',
            'region' => 'IL',
            'country' => 'US',
        ]);

        $event->refresh();

        $this->assertSame(EventStatus::Upcoming, $event->status);
        $this->assertSame(EventAttendanceMode::Hybrid, $event->attendance_mode);
        $this->assertNull($event->ends_at);
        $this->assertSame('America/Chicago', $event->timezone);
        $this->assertSame('Civic Hall', $event->venue_name);
        $this->assertSame('Chicago', $event->city);
        $this->assertSame('IL', $event->region);
        $this->assertSame('US', $event->country);
    }

    public function test_event_owns_external_references_and_can_select_one_primary_reference(): void
    {
        $event = Event::factory()->create();

        $secondary = EventExternalReference::factory()->forEvent($event)->create([
            'reference_type' => 'listing',
            'label' => 'Third-party listing',
        ]);
        $primary = EventExternalReference::factory()->forEvent($event)->urlOnly()->create([
            'reference_type' => 'public_page',
            'url' => 'https://events.example.test/summer-show',
            'label' => 'Official event page',
        ]);

        $event->update([
            'primary_external_reference_id' => $primary->getKey(),
        ]);
        $event->refresh();

        $this->assertCount(2, $event->externalReferences);
        $this->assertTrue($event->externalReferences->contains($secondary));
        $this->assertTrue($event->externalReferences->contains($primary));
        $this->assertTrue($event->primaryExternalReference->is($primary));
        $this->assertTrue($primary->event->is($event));
    }

    public function test_external_provider_identity_cannot_be_reused_across_events(): void
    {
        EventExternalReference::factory()->create([
            'provider_key' => 'website',
            'reference_type' => 'event_page',
            'external_id' => 'event-1001',
        ]);

        $this->expectException(QueryException::class);

        EventExternalReference::factory()->create([
            'provider_key' => 'website',
            'reference_type' => 'event_page',
            'external_id' => 'event-1001',
        ]);
    }

    public function test_force_deleting_a_selected_reference_nulls_the_event_pointer(): void
    {
        $event = Event::factory()->create();
        $reference = EventExternalReference::factory()->forEvent($event)->create();

        $event->update([
            'primary_external_reference_id' => $reference->getKey(),
        ]);

        $reference->forceDelete();

        $this->assertNull($event->refresh()->primary_external_reference_id);
    }

    public function test_force_deleting_an_event_cascades_its_external_references(): void
    {
        $event = Event::factory()->create();
        $reference = EventExternalReference::factory()->forEvent($event)->create();

        $event->update([
            'primary_external_reference_id' => $reference->getKey(),
        ]);

        $event->forceDelete();

        $this->assertDatabaseMissing('event_external_references', [
            'id' => $reference->getKey(),
        ]);
    }

    public function test_events_foundation_excludes_optional_module_and_generic_metadata_columns(): void
    {
        foreach ([
            'location_id',
            'series_id',
            'commerce_offer_id',
            'experience_id',
            'music_release_id',
            'image_url',
            'media_id',
            'meta',
        ] as $column) {
            $this->assertFalse(
                Schema::hasColumn('events', $column),
                "Unexpected optional or generic column [events.{$column}].",
            );
        }

        $this->assertSame(
            'must_be_empty',
            config('project_state.table_policies.events.mode'),
        );
        $this->assertSame(
            'must_be_empty',
            config('project_state.table_policies.event_external_references.mode'),
        );
    }

    /**
     * @param array<int, string> $columns
     */
    private function assertTableHasColumns(string $table, array $columns): void
    {
        $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Missing column [{$table}.{$column}].",
            );
        }
    }
}
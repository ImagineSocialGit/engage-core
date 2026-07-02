<?php

namespace Tests\Feature\Location;

use App\Modules\Core\Models\Contact;
use App\Modules\Location\Models\ContactLocation;
use App\Modules\Location\Models\Location;
use App\Modules\Location\Models\LocationArea;
use App\Modules\Location\Models\LocationAreaAssignment;
use App\Modules\Location\Providers\LocationModuleServiceProvider;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LocationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_module_is_registered_without_being_enabled_by_default(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'workflow',
            'flow_routes',
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'campaigns',
            'broadcasts',
            'webinars',
            'integrations',
            'reporting',
        ]);

        $modules = app(ModuleManager::class);

        $this->assertTrue($modules->known('location'));
        $this->assertFalse($modules->enabled('location'));
        $this->assertSame(['core'], $modules->dependencies('location'));
        $this->assertContains(LocationModuleServiceProvider::class, $modules->providers('location'));
    }

    public function test_location_foundation_tables_have_durable_generic_columns(): void
    {
        $this->assertTableHasColumns('locations', [
            'key',
            'name',
            'label',
            'type',
            'status',
            'address_line_1',
            'address_line_2',
            'city',
            'region',
            'postal_code',
            'country',
            'formatted_address',
            'latitude',
            'longitude',
            'timezone',
            'precision',
            'confidence',
            'source',
            'provider',
            'external_id',
            'external_url',
            'geocoded_at',
            'raw_payload',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('contact_locations', [
            'contact_id',
            'location_id',
            'subject_type',
            'subject_id',
            'type',
            'label',
            'status',
            'is_primary',
            'verified_at',
            'valid_from',
            'valid_until',
            'source',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('location_areas', [
            'key',
            'name',
            'description',
            'type',
            'status',
            'boundary_type',
            'country',
            'region',
            'city',
            'postal_code',
            'center_latitude',
            'center_longitude',
            'radius_meters',
            'geometry',
            'timezone',
            'is_service_area',
            'source',
            'provider',
            'external_id',
            'external_url',
            'settings',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('location_area_assignments', [
            'location_area_id',
            'location_id',
            'contact_id',
            'subject_type',
            'subject_id',
            'role',
            'status',
            'starts_at',
            'expires_at',
            'source',
            'meta',
            'deleted_at',
        ]);
    }

    public function test_locations_link_to_contacts_subjects_and_areas_without_core_location_columns(): void
    {
        $contact = Contact::factory()->create();

        $location = Location::factory()->geocoded()->create([
            'city' => 'Melbourne',
            'region' => 'FL',
            'country' => 'US',
        ]);

        $contactLocation = ContactLocation::factory()
            ->forSubject($contact)
            ->create([
                'contact_id' => $contact->id,
                'location_id' => $location->id,
                'type' => ContactLocation::TYPE_HOME,
                'is_primary' => true,
            ]);

        $area = LocationArea::factory()->create([
            'key' => 'space_coast',
            'name' => 'Space Coast',
        ]);

        $assignment = LocationAreaAssignment::factory()
            ->forSubject($contact)
            ->create([
                'location_area_id' => $area->id,
                'location_id' => $location->id,
                'contact_id' => $contact->id,
                'role' => LocationAreaAssignment::ROLE_SERVICEABLE,
            ]);

        $this->assertTrue($contactLocation->contact->is($contact));
        $this->assertTrue($contactLocation->location->is($location));
        $this->assertTrue($contactLocation->subject->is($contact));
        $this->assertTrue($location->contactLocations->contains($contactLocation));
        $this->assertTrue($area->assignments->contains($assignment));
        $this->assertTrue($assignment->locationArea->is($area));
        $this->assertTrue($assignment->location->is($location));
        $this->assertTrue($assignment->contact->is($contact));
        $this->assertTrue($assignment->subject->is($contact));

        $this->assertFalse(Schema::hasColumn('contacts', 'latitude'));
        $this->assertFalse(Schema::hasColumn('contacts', 'longitude'));
        $this->assertFalse(Schema::hasColumn('contacts', 'address_line_1'));
        $this->assertFalse(Schema::hasColumn('contacts', 'market'));
        $this->assertFalse(Schema::hasColumn('contacts', 'service_area'));
    }

    /**
     * @param  array<int, string>  $columns
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

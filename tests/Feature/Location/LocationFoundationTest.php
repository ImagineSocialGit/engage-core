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


    }

}
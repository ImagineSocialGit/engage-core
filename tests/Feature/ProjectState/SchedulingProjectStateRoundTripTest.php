<?php

namespace Tests\Feature\ProjectState;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\AppointmentLifecycleEvent;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookableServiceResourceRequirement;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Models\SchedulingHostResource;
use App\Modules\Scheduling\Models\SchedulingResource;
use App\Modules\Scheduling\Models\SchedulingResourceOccupancy;
use App\Support\ProjectState\ProjectStateDocumentCodec;
use App\Support\ProjectState\ProjectStateManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SchedulingProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_scheduling_durable_state_round_trips_and_remaps_configuration_references(): void
    {
        $creator = User::factory()->create([
            'email' => 'scheduler@example.test',
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Alex Example',
            'email' => 'alex@example.test',
        ]);
        $subject = ContactImportBatch::factory()->create([
            'name' => 'Scheduling Subject',
            'source' => 'project_state_test',
        ]);

        $host = SchedulingHost::factory()->forHostable($creator)->create([
            'key' => 'primary-advisor',
            'name' => 'Primary Advisor',
            'timezone' => 'America/Chicago',
            'capacity' => 2,
            'meta' => ['calendar' => ['label' => 'Primary']],
        ]);
        $service = BookableService::factory()->create([
            'key' => 'strategy-session',
            'name' => 'Strategy Session',
            'duration_mode' => BookableService::DURATION_MODE_RANGE,
            'duration_minutes' => 60,
            'minimum_duration_minutes' => 30,
            'maximum_duration_minutes' => 120,
            'timezone' => 'America/Chicago',
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_IN_PERSON,
            'in_person_arrangement' => BookableService::IN_PERSON_ARRANGEMENT_CUSTOMER_ADDRESS,
            'remote_method' => null,
            'location_type' => BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            'location_details' => [
                'address' => [
                    'formatted' => '100 Main St, Chicago, IL',
                ],
            ],
            'capacity' => 2,
            'requires_confirmation' => true,
            'is_public' => true,
            'sort_order' => 10,
            'meta' => ['audience' => 'public'],
        ]);
        $resource = SchedulingResource::query()->create([
            'key' => 'advisor-capacity',
            'name' => 'Advisor Capacity',
            'sort_order' => 10,
            'meta' => ['kind' => 'people'],
        ]);

        $assignment = BookableServiceHost::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'is_active' => true,
            'capacity_override' => 2,
            'sort_order' => 10,
            'meta' => ['source' => 'project_state_test'],
        ]);
        $hostResource = SchedulingHostResource::query()->create([
            'scheduling_host_id' => $host->id,
            'scheduling_resource_id' => $resource->id,
            'capacity' => 2,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $requirement = BookableServiceResourceRequirement::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_resource_id' => $resource->id,
            'quantity' => 1,
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $window = SchedulingAvailabilityWindow::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'window_type' => SchedulingAvailabilityWindowType::Absolute->value,
            'timezone' => 'America/Chicago',
            'starts_at' => CarbonImmutable::parse('2026-09-01 14:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 22:00:00 UTC'),
            'capacity' => 2,
            'is_available' => true,
            'meta' => ['reason' => 'launch_day'],
        ]);

        $original = Appointment::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'contact_id' => $contact->id,
            'primary_attendee_type' => ContactImportBatch::class,
            'primary_attendee_id' => $subject->id,
            'source_context_type' => ContactImportBatch::class,
            'source_context_id' => $subject->id,
            'idempotency_key' => 'project-state-original',
            'status' => Appointment::STATUS_CANCELED,
            'title' => 'Original strategy session',
            'location_type' => BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            'location_details' => [
                'address' => [
                    'formatted' => '100 Main St, Chicago, IL',
                ],
            ],
            'timezone' => 'America/Chicago',
            'starts_at' => CarbonImmutable::parse('2026-09-01 15:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 16:00:00 UTC'),
            'canceled_at' => CarbonImmutable::parse('2026-08-25 18:00:00 UTC'),
            'cancellation_reason' => 'Rescheduled',
            'source' => 'crm',
            'created_by_type' => User::class,
            'created_by_id' => $creator->id,
            'meta' => ['history' => ['kind' => 'original']],
        ]);
        $replacement = Appointment::query()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'contact_id' => $contact->id,
            'location_reference_type' => 'App\\Modules\\Location\\Models\\Location',
            'location_reference_id' => 4001,
            'primary_attendee_type' => ContactImportBatch::class,
            'primary_attendee_id' => $subject->id,
            'source_context_type' => ContactImportBatch::class,
            'source_context_id' => $subject->id,
            'rescheduled_from_id' => $original->id,
            'idempotency_key' => 'project-state-replacement',
            'status' => Appointment::STATUS_SCHEDULED,
            'title' => 'Replacement strategy session',
            'location_type' => BookableService::LOCATION_TYPE_CUSTOMER_SITE,
            'location_details' => [
                'address' => [
                    'formatted' => '100 Main St, Chicago, IL',
                ],
            ],
            'timezone' => 'America/Chicago',
            'starts_at' => CarbonImmutable::parse('2026-09-01 17:00:00 UTC'),
            'ends_at' => CarbonImmutable::parse('2026-09-01 18:00:00 UTC'),
            'source' => 'crm',
            'created_by_type' => User::class,
            'created_by_id' => $creator->id,
            'meta' => ['history' => ['kind' => 'replacement']],
        ]);
        $attendee = AppointmentAttendee::query()->create([
            'appointment_id' => $replacement->id,
            'attendee_type' => ContactImportBatch::class,
            'attendee_id' => $subject->id,
            'contact_id' => $contact->id,
            'name' => 'Scheduling Subject',
            'email' => 'alex@example.test',
            'role' => 'primary',
            'status' => AppointmentAttendee::STATUS_ACCEPTED,
            'responded_at' => CarbonImmutable::parse('2026-08-25 18:05:00 UTC'),
            'meta' => ['source' => 'project_state_test'],
        ]);
        $lifecycleEvent = AppointmentLifecycleEvent::query()->create([
            'appointment_id' => $replacement->id,
            'event_id' => '911db22e-c1d8-44bc-a33a-49b02d8cd7c2',
            'event_key' => AppointmentLifecycleEvent::EVENT_RESCHEDULED,
            'from_status' => Appointment::STATUS_CANCELED,
            'to_status' => Appointment::STATUS_SCHEDULED,
            'actor_type' => User::class,
            'actor_id' => $creator->id,
            'source' => 'crm',
            'reason' => 'Customer selected another time.',
            'context' => ['surface' => 'crm_scheduling'],
            'occurred_at' => CarbonImmutable::parse('2026-08-25 18:05:00 UTC'),
        ]);
        $occupancy = SchedulingResourceOccupancy::query()->create([
            'scheduling_resource_id' => $resource->id,
            'scheduling_host_id' => $host->id,
            'appointment_id' => $replacement->id,
            'booking_hold_id' => null,
            'quantity' => 1,
            'occupancy_starts_at' => CarbonImmutable::parse('2026-09-01 17:00:00 UTC'),
            'occupancy_ends_at' => CarbonImmutable::parse('2026-09-01 18:00:00 UTC'),
        ]);

        $sourceHostId = $host->id;
        $sourceServiceId = $service->id;
        $sourceResourceId = $resource->id;

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame((int) config('project_state.version'), $document['version']);
        $this->assertSame(
            (int) config('project_state.sections.scheduling.version'),
            $document['sections']['scheduling']['version'],
        );

        foreach ([
            'scheduling_hosts',
            'bookable_services',
            'scheduling_resources',
            'bookable_service_hosts',
            'scheduling_host_resources',
            'bookable_service_resource_requirements',
            'scheduling_availability_windows',
            'appointments',
            'appointment_attendees',
            'appointment_lifecycle_events',
            'scheduling_resource_occupancies',
        ] as $table) {
            $this->assertArrayHasKey(
                $table,
                $document['sections']['scheduling']['tables'],
            );
        }

        $this->assertArrayNotHasKey(
            'bookable_slot_offers',
            $document['sections']['scheduling']['tables'],
        );
        $this->assertArrayNotHasKey(
            'booking_holds',
            $document['sections']['scheduling']['tables'],
        );

        DB::table('scheduling_resource_occupancies')->delete();
        DB::table('appointment_lifecycle_events')->delete();
        DB::table('appointment_attendees')->delete();
        DB::table('appointments')->delete();
        DB::table('scheduling_availability_windows')->delete();
        DB::table('bookable_service_resource_requirements')->delete();
        DB::table('scheduling_host_resources')->delete();
        DB::table('bookable_service_hosts')->delete();
        DB::table('scheduling_resources')->delete();
        DB::table('bookable_services')->delete();
        DB::table('scheduling_hosts')->delete();
        DB::table('contacts')->delete();
        DB::table('contact_import_batches')->delete();
        DB::table('users')->delete();

        $validation = $projectState->validate($document);
        $this->assertTrue($validation['valid'], implode(PHP_EOL, $validation['errors']));

        $report = $projectState->import($document);
        $this->assertTrue($report['applied']);

        $restoredHost = SchedulingHost::query()
            ->where('key', 'primary-advisor')
            ->firstOrFail();
        $restoredService = BookableService::query()
            ->where('key', 'strategy-session')
            ->firstOrFail();
        $restoredResource = SchedulingResource::query()
            ->where('key', 'advisor-capacity')
            ->firstOrFail();
        $restoredReplacement = Appointment::query()->findOrFail($replacement->id);
        $restoredAttendee = AppointmentAttendee::query()->findOrFail($attendee->id);
        $restoredLifecycleEvent = AppointmentLifecycleEvent::query()
            ->findOrFail($lifecycleEvent->id);
        $restoredOccupancy = SchedulingResourceOccupancy::query()
            ->findOrFail($occupancy->id);

        $this->assertNotSame($sourceHostId, $restoredHost->id);
        $this->assertNotSame($sourceServiceId, $restoredService->id);
        $this->assertNotSame($sourceResourceId, $restoredResource->id);

        $this->assertNull($restoredHost->hostable_type);
        $this->assertNull($restoredHost->hostable_id);
        $this->assertEquals(
            ['calendar' => ['label' => 'Primary']],
            $restoredHost->meta,
        );
        $this->assertSame(BookableService::DURATION_MODE_RANGE, $restoredService->duration_mode);
        $this->assertSame(30, $restoredService->minimum_duration_minutes);
        $this->assertSame(120, $restoredService->maximum_duration_minutes);
        $this->assertSame(BookableService::APPOINTMENT_FORMAT_IN_PERSON, $restoredService->appointment_format);
        $this->assertSame(BookableService::IN_PERSON_ARRANGEMENT_CUSTOMER_ADDRESS, $restoredService->in_person_arrangement);
        $this->assertNull($restoredService->remote_method);
        $this->assertEquals(
            ['address' => ['formatted' => '100 Main St, Chicago, IL']],
            $restoredService->location_details,
        );

        $this->assertDatabaseHas('bookable_service_hosts', [
            'bookable_service_id' => $restoredService->id,
            'scheduling_host_id' => $restoredHost->id,
            'capacity_override' => 2,
        ]);
        $this->assertDatabaseHas('scheduling_host_resources', [
            'scheduling_host_id' => $restoredHost->id,
            'scheduling_resource_id' => $restoredResource->id,
            'capacity' => 2,
        ]);
        $this->assertDatabaseHas('bookable_service_resource_requirements', [
            'bookable_service_id' => $restoredService->id,
            'scheduling_resource_id' => $restoredResource->id,
            'quantity' => 1,
        ]);
        $this->assertDatabaseHas('scheduling_availability_windows', [
            'id' => $window->id,
            'bookable_service_id' => $restoredService->id,
            'scheduling_host_id' => $restoredHost->id,
            'capacity' => 2,
        ]);

        $this->assertSame($restoredService->id, $restoredReplacement->bookable_service_id);
        $this->assertSame($restoredHost->id, $restoredReplacement->scheduling_host_id);
        $this->assertSame($contact->id, $restoredReplacement->contact_id);
        $this->assertSame($original->id, $restoredReplacement->rescheduled_from_id);
        $this->assertSame(ContactImportBatch::class, $restoredReplacement->primary_attendee_type);
        $this->assertSame($subject->id, $restoredReplacement->primary_attendee_id);
        $this->assertSame(ContactImportBatch::class, $restoredReplacement->source_context_type);
        $this->assertSame($subject->id, $restoredReplacement->source_context_id);
        $this->assertNull($restoredReplacement->location_reference_type);
        $this->assertNull($restoredReplacement->location_reference_id);
        $this->assertNull($restoredReplacement->created_by_type);
        $this->assertNull($restoredReplacement->created_by_id);
        $this->assertEquals(
            ['address' => ['formatted' => '100 Main St, Chicago, IL']],
            $restoredReplacement->location_details,
        );

        $this->assertSame(ContactImportBatch::class, $restoredAttendee->attendee_type);
        $this->assertSame($subject->id, $restoredAttendee->attendee_id);
        $this->assertSame($contact->id, $restoredAttendee->contact_id);

        $this->assertNull($restoredLifecycleEvent->actor_type);
        $this->assertNull($restoredLifecycleEvent->actor_id);
        $this->assertEquals(
            ['surface' => 'crm_scheduling'],
            $restoredLifecycleEvent->context,
        );

        $this->assertSame($restoredResource->id, $restoredOccupancy->scheduling_resource_id);
        $this->assertSame($restoredHost->id, $restoredOccupancy->scheduling_host_id);
        $this->assertSame($replacement->id, $restoredOccupancy->appointment_id);
        $this->assertNull($restoredOccupancy->booking_hold_id);
        $this->assertSame(1, $restoredOccupancy->quantity);

        $this->assertSame(1, BookableServiceHost::query()->count());
        $this->assertSame(1, SchedulingHostResource::query()->count());
        $this->assertSame(1, BookableServiceResourceRequirement::query()->count());

        $this->assertNotSame($assignment->id, BookableServiceHost::query()->sole()->id);
        $this->assertNotSame($hostResource->id, SchedulingHostResource::query()->sole()->id);
        $this->assertNotSame($requirement->id, BookableServiceResourceRequirement::query()->sole()->id);
    }

    public function test_scheduling_contract_keeps_ephemeral_booking_state_outside_the_section(): void
    {
        $section = config('project_state.sections.scheduling');
        $policies = config('project_state.table_policies');

        $this->assertTrue($section['optional'] ?? false);
        $this->assertEquals([
            'scheduling_hosts',
            'bookable_services',
            'scheduling_availability_windows',
            'appointments',
            'appointment_attendees',
            'bookable_service_hosts',
            'appointment_lifecycle_events',
            'bookable_slot_offers',
            'booking_holds',
            'scheduling_resources',
            'scheduling_host_resources',
            'bookable_service_resource_requirements',
            'scheduling_resource_occupancies',
        ], $section['activation_tables'] ?? []);

        foreach ([
            'scheduling_hosts',
            'bookable_services',
            'scheduling_resources',
            'bookable_service_hosts',
            'scheduling_host_resources',
            'bookable_service_resource_requirements',
            'scheduling_availability_windows',
            'appointments',
            'appointment_attendees',
            'appointment_lifecycle_events',
            'scheduling_resource_occupancies',
        ] as $table) {
            $this->assertArrayHasKey($table, $section['tables'] ?? []);
            $this->assertArrayNotHasKey($table, $policies);
        }

        foreach ([
            'bookable_slot_offers',
            'booking_holds',
        ] as $table) {
            $this->assertArrayNotHasKey($table, $section['tables'] ?? []);
            $this->assertSame('must_be_empty', $policies[$table]['mode'] ?? null);
        }
    }

    public function test_optional_scheduling_section_may_be_absent_from_a_current_source_document(): void
    {
        $manager = app(ProjectStateManager::class);
        $document = $manager->export();

        unset($document['sections']['scheduling']);
        $document['checksum'] = app(ProjectStateDocumentCodec::class)->checksum($document);

        $validation = $manager->validate($document);

        $this->assertTrue($validation['valid']);
        $this->assertSame([], $validation['errors']);

        $report = $manager->import($document);

        $this->assertTrue($report['applied']);
        $this->assertArrayNotHasKey(
            'scheduling_hosts',
            $report['applied_counts'],
        );
    }
}
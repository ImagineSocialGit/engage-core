<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchedulingFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_module_is_registered_without_being_enabled_by_default(): void
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

        $this->assertTrue($modules->known('scheduling'));
        $this->assertFalse($modules->enabled('scheduling'));
        $this->assertSame(['core'], $modules->dependencies('scheduling'));
        $this->assertContains(SchedulingModuleServiceProvider::class, $modules->providers('scheduling'));
    }

    public function test_scheduling_foundation_tables_have_durable_generic_columns(): void
    {
        $this->assertTableHasColumns('bookable_services', [
            'key',
            'name',
            'status',
            'duration_minutes',
            'buffer_before_minutes',
            'buffer_after_minutes',
            'location_type',
            'location_details',
            'capacity',
            'requires_confirmation',
            'is_public',
            'source',
            'provider',
            'external_id',
            'external_url',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('scheduling_availability_windows', [
            'bookable_service_id',
            'owner_type',
            'owner_id',
            'timezone',
            'weekday',
            'starts_at',
            'ends_at',
            'start_time',
            'end_time',
            'capacity',
            'rrule',
            'is_available',
            'source',
            'provider',
            'external_id',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('appointments', [
            'bookable_service_id',
            'contact_id',
            'primary_attendee_type',
            'primary_attendee_id',
            'rescheduled_from_id',
            'status',
            'title',
            'location_type',
            'location_details',
            'timezone',
            'starts_at',
            'ends_at',
            'confirmed_at',
            'completed_at',
            'no_show_at',
            'canceled_at',
            'cancellation_reason',
            'source',
            'provider',
            'external_id',
            'external_url',
            'created_by_type',
            'created_by_id',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('appointment_attendees', [
            'appointment_id',
            'attendee_type',
            'attendee_id',
            'contact_id',
            'name',
            'email',
            'phone',
            'role',
            'status',
            'responded_at',
            'joined_at',
            'canceled_at',
            'meta',
            'deleted_at',
        ]);
    }

    public function test_bookable_services_have_availability_windows_and_appointments(): void
    {
        $service = BookableService::factory()->create();

        $availabilityWindow = SchedulingAvailabilityWindow::factory()->create([
            'bookable_service_id' => $service->id,
            'timezone' => 'America/Chicago',
        ]);

        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
        ]);

        $this->assertTrue($service->availabilityWindows->contains($availabilityWindow));
        $this->assertTrue($service->appointments->contains($appointment));
        $this->assertSame('America/Chicago', $availabilityWindow->timezone);
    }

    public function test_appointments_can_link_contacts_and_attendees_without_owning_contact_identity(): void
    {
        $contact = Contact::factory()->create();
        $appointment = Appointment::factory()
            ->forPrimaryAttendee($contact)
            ->create([
                'contact_id' => $contact->id,
            ]);

        $attendee = AppointmentAttendee::factory()
            ->forContact($contact)
            ->accepted()
            ->create([
                'appointment_id' => $appointment->id,
            ]);

        $this->assertTrue($appointment->contact->is($contact));
        $this->assertTrue($appointment->primaryAttendee->is($contact));
        $this->assertTrue($appointment->attendees->contains($attendee));
        $this->assertTrue($attendee->contact->is($contact));
        $this->assertTrue($attendee->attendee->is($contact));
    }

    public function test_appointments_can_track_reschedule_lineage_without_a_separate_cancellation_table(): void
    {
        $original = Appointment::factory()->canceled('Rescheduled by client')->create();

        $replacement = Appointment::factory()->create([
            'rescheduled_from_id' => $original->id,
        ]);

        $this->assertTrue($replacement->rescheduledFrom->is($original));
        $this->assertTrue($original->rescheduledAppointments->contains($replacement));
        $this->assertSame(Appointment::STATUS_CANCELED, $original->status);
        $this->assertSame('Rescheduled by client', $original->cancellation_reason);
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

<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Services\SchedulingConfigurationWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class SchedulingAppointmentFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_bookable_services_persist_business_language_appointment_format_and_internal_commitment_type(): void
    {
        $this->assertTrue(Schema::hasColumns('bookable_services', [
            'appointment_format',
            'in_person_arrangement',
            'remote_method',
        ]));

        $service = app(SchedulingConfigurationWriter::class)->createService([
            ...$this->baseAttributes('remote-phone'),
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_REMOTE,
            'in_person_arrangement' => null,
            'remote_method' => BookableService::REMOTE_METHOD_PHONE,
        ]);

        $this->assertSame(BookableService::APPOINTMENT_FORMAT_REMOTE, $service->appointment_format);
        $this->assertNull($service->in_person_arrangement);
        $this->assertSame(BookableService::REMOTE_METHOD_PHONE, $service->remote_method);
        $this->assertSame(BookableService::LOCATION_TYPE_PHONE, $service->location_type);
        $this->assertTrue($service->hasCompleteAppointmentFormat());
        $this->assertSame('Remote', $service->appointmentFormatLabel());
        $this->assertSame('Phone call', $service->appointmentMethodLabel());
    }

    public function test_in_person_business_location_derives_fixed_commitment_and_normalizes_address(): void
    {
        $service = app(SchedulingConfigurationWriter::class)->createService([
            ...$this->baseAttributes('office-visit'),
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_IN_PERSON,
            'in_person_arrangement' => BookableService::IN_PERSON_ARRANGEMENT_BUSINESS_LOCATION,
            'remote_method' => null,
            'location_label' => 'Main office',
            'location_instructions' => 'Bring a photo ID.',
            'location_address_line_1' => '100 Main St',
            'location_address_line_2' => null,
            'location_city' => 'Denver',
            'location_region' => 'CO',
            'location_postal_code' => '80202',
            'location_country' => 'US',
        ]);

        $this->assertSame(BookableService::LOCATION_TYPE_FIXED, $service->location_type);
        $this->assertSame('In person', $service->appointmentFormatLabel());
        $this->assertSame('Business location', $service->appointmentMethodLabel());
        $this->assertSame('Main office', $service->location_details['label']);
        $this->assertSame('Bring a photo ID.', $service->location_details['instructions']);
        $this->assertSame('100 Main St', data_get($service->location_details, 'address.address_line_1'));
    }

    public function test_public_service_requires_a_complete_appointment_format(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(SchedulingConfigurationWriter::class)->createService([
            ...$this->baseAttributes('incomplete-public'),
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_REMOTE,
            'in_person_arrangement' => null,
            'remote_method' => null,
        ]);
    }

    public function test_legacy_location_type_input_is_mapped_into_the_new_service_contract(): void
    {
        $service = app(SchedulingConfigurationWriter::class)->createService([
            ...$this->baseAttributes('legacy-virtual'),
            'location_type' => BookableService::LOCATION_TYPE_VIRTUAL,
            'location_url' => 'https://example.test/meeting',
        ]);

        $this->assertSame(BookableService::APPOINTMENT_FORMAT_REMOTE, $service->appointment_format);
        $this->assertNull($service->in_person_arrangement);
        $this->assertSame(BookableService::REMOTE_METHOD_VIRTUAL_MEETING, $service->remote_method);
        $this->assertSame(BookableService::LOCATION_TYPE_VIRTUAL, $service->location_type);
        $this->assertTrue($service->hasCompleteAppointmentFormat());
    }

    public function test_project_state_scheduling_contract_exports_appointment_format_fields(): void
    {
        $section = config('project_state.sections.scheduling');
        $columns = data_get($section, 'tables.bookable_services.columns', []);

        $this->assertSame(2, data_get($section, 'version'));
        $this->assertContains('appointment_format', $columns);
        $this->assertContains('in_person_arrangement', $columns);
        $this->assertContains('remote_method', $columns);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseAttributes(string $key): array
    {
        return [
            'key' => $key,
            'name' => str($key)->replace('-', ' ')->title()->toString(),
            'description' => null,
            'status' => BookableService::STATUS_ACTIVE,
            'duration_mode' => BookableService::DURATION_MODE_FIXED,
            'duration_minutes' => 60,
            'minimum_duration_minutes' => null,
            'maximum_duration_minutes' => null,
            'slot_interval_minutes' => 15,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 60,
            'cancellation_notice_minutes' => 0,
            'reschedule_notice_minutes' => 0,
            'timezone' => 'UTC',
            'location_label' => null,
            'location_instructions' => null,
            'location_url' => null,
            'location_address_line_1' => null,
            'location_address_line_2' => null,
            'location_city' => null,
            'location_region' => null,
            'location_postal_code' => null,
            'location_country' => null,
            'capacity' => 1,
            'requires_confirmation' => false,
            'is_public' => true,
            'sort_order' => 0,
        ];
    }
}
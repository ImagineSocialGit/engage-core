<?php

namespace Tests\Feature\Scheduling;

use Tests\TestCase;

class PublicAppointmentSummaryPresentationTest extends TestCase
{
    public function test_physical_summary_uses_the_committed_address_without_exposing_internal_arrangement_labels(): void
    {
        $html = view('scheduling.public.partials.appointment-summary', [
            'summary' => $this->summary([
                'location_type' => 'customer_site',
                'appointment_method_label' => 'Customer-provided address',
                'location_label' => 'House',
                'location_address' => '1262 Place, City, State 12345, US',
                'location_instructions' => 'Know stuff',
            ]),
        ])->render();

        $this->assertStringContainsString(
            'data-appointment-meeting-method="in_person"',
            $html,
        );
        $this->assertStringContainsString(
            'data-appointment-location-address',
            $html,
        );
        $this->assertStringContainsString(
            '1262 Place, City, State 12345, US',
            $html,
        );
        $this->assertStringContainsString(
            'data-appointment-summary="preparation"',
            $html,
        );
        $this->assertStringContainsString(
            'data-appointment-timezone="America/New_York"',
            $html,
        );
        $this->assertStringNotContainsString(
            'Customer-provided address',
            $html,
        );
        $this->assertStringNotContainsString(
            'House',
            $html,
        );
    }

    public function test_phone_summary_keeps_the_meeting_method_and_preparation_as_separate_public_concepts(): void
    {
        $html = view('scheduling.public.partials.appointment-summary', [
            'summary' => $this->summary([
                'location_type' => 'phone',
                'appointment_method_label' => 'Phone call',
                'location_label' => 'House',
                'location_address' => null,
                'location_instructions' => 'Know stuff',
            ]),
        ])->render();

        $this->assertStringContainsString(
            'data-appointment-meeting-method="phone"',
            $html,
        );
        $this->assertStringContainsString(
            'data-appointment-summary="preparation"',
            $html,
        );
        $this->assertStringNotContainsString(
            'data-appointment-location-address',
            $html,
        );
        $this->assertStringNotContainsString(
            'House',
            $html,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function summary(array $overrides = []): array
    {
        return array_replace([
            'service_name' => 'Training',
            'is_range' => false,
            'date_label' => 'Thursday, August 27, 2026',
            'interval_label' => null,
            'time_label' => '3:45 PM–4:45 PM',
            'timezone' => 'America/New_York',
            'timezone_label' => 'Eastern Time',
            'appointment_method_label' => 'Virtual meeting',
            'location_type' => 'virtual',
            'location_label' => null,
            'location_address' => null,
            'location_instructions' => null,
        ], $overrides);
    }
}
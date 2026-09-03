<?php

namespace Tests\Feature\Scheduling;

use Tests\TestCase;

class PublicAppointmentSummaryPresentationTest extends TestCase
{
    public function test_physical_summary_uses_two_line_committed_address_and_separate_preparation(): void
    {
        $html = $this->renderSummary([
            'location_type' => 'customer_site',
            'appointment_method_label' => 'Customer-provided address',
            'location_label' => 'House',
            'location_address' => '1262 Place, City, State 12345, US',
            'location_instructions' => 'Know stuff',
            'location_presentation' => [
                'type' => 'customer_site',
                'method_label' => 'In person',
                'method_detail' => null,
                'name' => null,
                'instructions' => 'Know stuff',
                'address_lines' => [
                    '1262 Place',
                    'City, State 12345',
                ],
            ],
            ]);

        $this->assertStringContainsString(
            'data-appointment-meeting-method="in_person"',
            $html,
        );
        $this->assertStringContainsString(
            'data-appointment-location-address',
            $html,
        );
        $this->assertStringContainsString('1262 Place', $html);
        $this->assertStringContainsString('City, State 12345', $html);
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
        $html = $this->renderSummary([
            'location_type' => 'phone',
            'appointment_method_label' => 'Phone call',
            'location_label' => 'House',
            'location_address' => null,
            'location_instructions' => 'Know stuff',
            'location_presentation' => [
                'type' => 'phone',
                'method_label' => 'Phone call',
                'method_detail' => 'The team will call the phone number you provide.',
                'name' => null,
                'instructions' => 'Know stuff',
                'address_lines' => [],
            ],
            ]);

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

    public function test_fixed_business_location_may_show_its_public_name_without_merging_instructions_into_the_address(): void
    {
        $html = $this->renderSummary([
            'location_type' => 'fixed',
            'location_presentation' => [
                'type' => 'fixed',
                'method_label' => 'In person',
                'method_detail' => null,
                'name' => 'Main Office',
                'instructions' => 'Use the north entrance.',
                'address_lines' => [
                    '50 Office Plaza',
                    'Denver, CO 80205',
                ],
            ],
            ]);

        $this->assertStringContainsString('Main Office', $html);
        $this->assertStringContainsString('50 Office Plaza', $html);
        $this->assertStringContainsString('Denver, CO 80205', $html);
        $this->assertStringContainsString('data-booking-preparation', $html);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function renderSummary(array $overrides = []): string
    {
        return view('scheduling.public.partials.appointment-summary', [
            'summary' => $this->summary($overrides),
            'publicPresentation' => [
                'style' => config('scheduling.public_presentation_style_defaults', []),
            ],
        ])->render();
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
            'location_presentation' => [
                'type' => 'virtual',
                'method_label' => 'Virtual meeting',
                'method_detail' => 'Online meeting details will be provided by the team.',
                'name' => null,
                'instructions' => null,
                'address_lines' => [],
            ],
        ], $overrides);
    }
}
<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\EventDefinitions\SchedulingPublicBookingEventDefinitionContributor;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\ReadModels\SchedulingBookingFunnelFactContributor;
use App\Support\Reporting\Data\ReportingProjectionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingReportingFunnelContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduling_declares_bounded_public_booking_events_for_its_host(): void
    {
        config()->set('scheduling.public.host', 'booking.example.test');

        $definitions = collect(iterator_to_array(
            app(SchedulingPublicBookingEventDefinitionContributor::class)->definitions(),
        ));

        $this->assertEqualsCanonicalizing([
            'scheduling.booking.page_view',
            'scheduling.booking.service_selected',
            'scheduling.booking.availability_viewed',
            'scheduling.booking.time_selected',
            'scheduling.booking.verification_requested',
            'scheduling.booking.verification_completed',
            'scheduling.booking.details_started',
            'scheduling.booking.submit_attempt',
            'scheduling.booking.validation_failed',
        ], $definitions->pluck('key')->all());

        $this->assertTrue($definitions->every(
            fn ($definition): bool =>
                $definition->funnelEligible
                && $definition->browserHosts === ['booking.example.test'],
        ));
    }

    public function test_public_appointments_contribute_durable_outcome_facts_with_submit_correlation(): void
    {
        $service = BookableService::factory()->create([
            'key' => 'strategy-call',
            'requires_confirmation' => true,
        ]);
        $appointment = Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'source' => 'public_booking',
            'status' => Appointment::STATUS_PENDING,
            'location_type' => BookableService::LOCATION_TYPE_PHONE,
            'location_details' => ['label' => 'Phone call'],
            'created_at' => CarbonImmutable::parse('2026-08-27 14:00:00 UTC'),
            'meta' => [
                'reporting' => [
                    'public_submission_attempt_id' => '484925e0-0c04-4e43-a465-a4c75bd0db0f',
                ],
            ],
        ]);

        $facts = collect(iterator_to_array(
            app(SchedulingBookingFunnelFactContributor::class)->facts(
                new ReportingProjectionWindow(
                    startsAt: CarbonImmutable::parse('2026-08-27 00:00:00 UTC'),
                    endsAt: CarbonImmutable::parse('2026-08-27 23:59:59 UTC'),
                ),
            ),
        ));

        $fact = $facts->sole();

        $this->assertSame('scheduling.public_booking', $fact->key);
        $this->assertSame((string) $appointment->id, $fact->subjectId);
        $this->assertSame(
            '484925e0-0c04-4e43-a465-a4c75bd0db0f',
            $fact->correlationId,
        );
        $this->assertSame('strategy-call', $fact->dimensions['service_key']);
        $this->assertSame(Appointment::STATUS_PENDING, $fact->values['appointment_status']);
        $this->assertTrue($fact->values['requires_confirmation']);
    }

    public function test_public_booking_script_uses_reporting_client_without_collecting_contact_values(): void
    {
        $script = file_get_contents(resource_path('js/pages/scheduling-public-booking.js'));

        $this->assertIsString($script);
        $this->assertStringContainsString("'scheduling.booking.submit_attempt'", $script);
        $this->assertStringContainsString("'scheduling.booking.validation_failed'", $script);
        $this->assertStringContainsString('public_submission_attempt_id', $script);
        $this->assertStringNotContainsString('destination: destination.value', $script);
        $this->assertStringNotContainsString('email: email.value', $script);
        $this->assertStringNotContainsString('phone: phone.value', $script);
    }
}
<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Services\SchedulingSetupReadiness;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulingSetupReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-26 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_readiness_progresses_from_empty_to_internally_ready_without_requiring_a_host(): void
    {
        $readiness = app(SchedulingSetupReadiness::class);

        $empty = $readiness->summary();

        $this->assertTrue($empty['empty']);
        $this->assertFalse($empty['has_service']);
        $this->assertFalse($empty['has_availability']);
        $this->assertFalse($empty['internal_ready']);

        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'is_public' => false,
        ]);

        $serviceOnly = $readiness->summary();

        $this->assertFalse($serviceOnly['empty']);
        $this->assertTrue($serviceOnly['has_service']);
        $this->assertFalse($serviceOnly['has_active_host']);
        $this->assertFalse($serviceOnly['has_availability']);
        $this->assertFalse($serviceOnly['internal_ready']);

        SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-08-27 14:00:00 UTC'),
                CarbonImmutable::parse('2026-08-27 15:00:00 UTC'),
            )
            ->serviceWide($service)
            ->create([
                'is_available' => true,
                'timezone' => 'UTC',
            ]);

        $ready = $readiness->summary();

        $this->assertTrue($ready['has_service']);
        $this->assertFalse($ready['has_active_host']);
        $this->assertTrue($ready['has_availability']);
        $this->assertTrue($ready['internal_ready']);
    }

    public function test_incomplete_public_appointment_format_fails_public_readiness_closed(): void
    {
        config()->set('scheduling.public.enabled', true);

        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'is_public' => true,
            'appointment_format' => BookableService::APPOINTMENT_FORMAT_REMOTE,
            'in_person_arrangement' => null,
            'remote_method' => null,
            'location_type' => BookableService::LOCATION_TYPE_PHONE,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-08-27 14:00:00 UTC'),
                CarbonImmutable::parse('2026-08-27 15:00:00 UTC'),
            )
            ->serviceWide($service)
            ->create([
                'is_available' => true,
                'timezone' => 'UTC',
            ]);

        $summary = app(SchedulingSetupReadiness::class)->summary();

        $this->assertTrue($summary['internal_ready']);
        $this->assertFalse($summary['has_public_service']);
        $this->assertTrue($summary['has_incomplete_public_service']);
        $this->assertFalse($summary['public_ready']);
    }

    public function test_inactive_or_blackout_only_configuration_does_not_report_ready(): void
    {
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
        ]);
        $inactiveHost = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_INACTIVE,
        ]);

        SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-08-27 14:00:00 UTC'),
                CarbonImmutable::parse('2026-08-27 15:00:00 UTC'),
            )
            ->forServiceAndHost($service, $inactiveHost)
            ->create([
                'is_available' => true,
                'timezone' => 'UTC',
            ]);

        SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-08-28 14:00:00 UTC'),
                CarbonImmutable::parse('2026-08-28 15:00:00 UTC'),
            )
            ->serviceWide($service)
            ->create([
                'is_available' => false,
                'timezone' => 'UTC',
            ]);

        SchedulingAvailabilityWindow::factory()
            ->absolute(
                CarbonImmutable::parse('2026-08-25 14:00:00 UTC'),
                CarbonImmutable::parse('2026-08-25 15:00:00 UTC'),
            )
            ->serviceWide($service)
            ->create([
                'is_available' => true,
                'timezone' => 'UTC',
            ]);

        $summary = app(SchedulingSetupReadiness::class)->summary();

        $this->assertTrue($summary['has_service']);
        $this->assertFalse($summary['has_active_host']);
        $this->assertFalse($summary['has_availability']);
        $this->assertFalse($summary['internal_ready']);
    }
}
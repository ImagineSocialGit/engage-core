<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingHost;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SchedulingHostAndServiceFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_and_service_policy_tables_have_expected_columns(): void
    {
        $this->assertTableHasColumns('scheduling_hosts', [
            'key',
            'name',
            'status',
            'hostable_type',
            'hostable_id',
            'timezone',
            'capacity',
            'email',
            'phone',
            'sort_order',
            'source',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('bookable_services', [
            'duration_minutes',
            'slot_interval_minutes',
            'buffer_before_minutes',
            'buffer_after_minutes',
            'minimum_notice_minutes',
            'booking_horizon_days',
            'cancellation_notice_minutes',
            'reschedule_notice_minutes',
            'timezone',
            'capacity',
        ]);

        $this->assertTableHasColumns('bookable_service_hosts', [
            'bookable_service_id',
            'scheduling_host_id',
            'is_active',
            'capacity_override',
            'sort_order',
            'meta',
        ]);
    }

    public function test_bookable_service_policy_defaults_are_executable(): void
    {
        $service = BookableService::query()->create([
            'key' => 'consultation',
            'name' => 'Consultation',
            'duration_minutes' => 60,
        ]);

        $this->assertSame(BookableService::STATUS_ACTIVE, $service->status);
        $this->assertSame(15, $service->slot_interval_minutes);
        $this->assertSame(0, $service->buffer_before_minutes);
        $this->assertSame(0, $service->buffer_after_minutes);
        $this->assertSame(0, $service->minimum_notice_minutes);
        $this->assertSame(60, $service->booking_horizon_days);
        $this->assertSame(0, $service->cancellation_notice_minutes);
        $this->assertSame(0, $service->reschedule_notice_minutes);
        $this->assertSame('UTC', $service->timezone);
        $this->assertSame(1, $service->capacity);
        $this->assertFalse($service->requires_confirmation);
        $this->assertFalse($service->is_public);
    }

    public function test_scheduling_hosts_may_link_to_a_generic_hostable_record(): void
    {
        $user = User::factory()->create();

        $host = SchedulingHost::factory()
            ->forHostable($user)
            ->create([
                'timezone' => 'America/Chicago',
                'capacity' => 2,
            ]);

        $this->assertTrue($host->hostable->is($user));
        $this->assertSame('America/Chicago', $host->timezone);
        $this->assertSame(2, $host->capacity);
    }

    public function test_services_and_hosts_have_first_class_assignment_relationships(): void
    {
        $service = BookableService::factory()->create();
        $host = SchedulingHost::factory()->create();

        $assignment = BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'capacity_override' => 3,
            'sort_order' => 10,
            'meta' => [
                'assignment_source' => 'test',
            ],
        ]);

        $this->assertTrue($service->hostAssignments->contains($assignment));
        $this->assertTrue($host->serviceAssignments->contains($assignment));
        $this->assertTrue($service->schedulingHosts->contains($host));
        $this->assertTrue($host->bookableServices->contains($service));
        $this->assertTrue($assignment->bookableService->is($service));
        $this->assertTrue($assignment->schedulingHost->is($host));
        $this->assertSame(3, $assignment->capacity_override);
        $this->assertSame('test', $assignment->meta['assignment_source']);
    }

    public function test_a_service_and_host_pair_can_only_be_assigned_once(): void
    {
        $service = BookableService::factory()->create();
        $host = SchedulingHost::factory()->create();

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);

        $this->expectException(QueryException::class);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
        ]);
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
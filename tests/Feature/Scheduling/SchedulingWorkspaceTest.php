<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Support\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulingWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-03 12:00:00 UTC');
        $this->enableScheduling();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_workspace_requires_authentication_and_enabled_scheduling_module(): void
    {
        $this->get(route('crm.scheduling.index'))
            ->assertRedirect(route('login'));

        config()->set('modules.enabled', ['core']);

        $this->actingAs(User::factory()->create())
            ->get(route('crm.scheduling.index'))
            ->assertNotFound();
    }

    public function test_scheduling_navigation_and_workspace_present_upcoming_operational_state(): void
    {
        $user = User::factory()->create();
        $service = $this->service(['name' => 'Consultation']);
        $contact = Contact::factory()->create(['name' => 'Upcoming Contact']);
        $future = CarbonImmutable::parse('2026-08-04 09:00:00 UTC');

        Appointment::factory()->create([
            'bookable_service_id' => $service->id,
            'contact_id' => $contact->id,
            'status' => Appointment::STATUS_PENDING,
            'title' => 'Pending Consultation',
            'starts_at' => $future,
            'ends_at' => $future->addHour(),
        ]);
        Appointment::factory()->completed()->create([
            'bookable_service_id' => $service->id,
            'title' => 'Completed Appointment',
            'starts_at' => $future,
            'ends_at' => $future->addHour(),
        ]);

        $navigation = app(ModuleManager::class)->navigationItems();

        $this->assertTrue(collect($navigation)->contains(
            fn (array $item): bool =>
                $item['route'] === 'crm.scheduling.index'
                && $item['label'] === 'Scheduling',
        ));

        $response = $this->actingAs($user)
            ->get(route('crm.scheduling.index'));

        $response
            ->assertOk()
            ->assertSee('Pending Consultation')
            ->assertSee('Upcoming Contact')
            ->assertSee('Awaiting confirmation')
            ->assertDontSee('Completed Appointment')
            ->assertSee('Consultation');
    }

    public function test_workspace_renders_host_specific_availability_and_creates_scheduled_appointment(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'Alex Example',
            'email' => 'alex@example.test',
            'phone' => '15555550100',
        ]);
        [$service, $host] = $this->hostedService([
            'name' => 'Strategy Session',
        ]);
        $startsAt = CarbonImmutable::parse('2026-08-04 09:00:00 UTC');
        $this->availability($service, $host, $startsAt, $startsAt->addHour());

        $this->actingAs($user)
            ->get(route('crm.scheduling.index', [
                'bookable_service_id' => $service->id,
                'scheduling_host_id' => $host->id,
                'date' => '2026-08-04',
            ]))
            ->assertOk()
            ->assertSee('Strategy Session')
            ->assertSee($host->name)
            ->assertSee('9:00 AM–10:00 AM');

        $key = (string) Str::uuid();

        $response = $this->actingAs($user)
            ->from(route('crm.scheduling.index', [
                'bookable_service_id' => $service->id,
                'scheduling_host_id' => $host->id,
                'date' => '2026-08-04',
            ]))
            ->post(route('crm.scheduling.appointments.store'), [
                'contact_id' => $contact->id,
                'bookable_service_id' => $service->id,
                'scheduling_host_id' => $host->id,
                'starts_at' => $startsAt->toIso8601String(),
                'idempotency_key' => $key,
            ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('success', 'Appointment scheduled.');

        $appointment = Appointment::query()->sole();
        $attendee = AppointmentAttendee::query()->sole();

        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);
        $this->assertSame($service->id, $appointment->bookable_service_id);
        $this->assertSame($host->id, $appointment->scheduling_host_id);
        $this->assertSame($contact->id, $appointment->contact_id);
        $this->assertSame($key, $appointment->idempotency_key);
        $this->assertSame('crm', $appointment->source);
        $this->assertSame('crm_scheduling', data_get($appointment->meta, 'creation.surface'));
        $this->assertSame(AppointmentAttendee::STATUS_ACCEPTED, $attendee->status);
        $this->assertSame('Alex Example', $attendee->name);
        $this->assertSame('alex@example.test', $attendee->email);
        $this->assertSame('15555550100', $attendee->phone);
        $this->assertSame(1, $appointment->lifecycleEvents()->count());
    }

    public function test_confirmation_required_and_unhosted_services_follow_service_policy(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $pendingService = $this->service([
            'name' => 'Approval Required',
            'requires_confirmation' => true,
        ]);
        $pendingStart = CarbonImmutable::parse('2026-08-04 10:00:00 UTC');
        $this->availability(
            $pendingService,
            null,
            $pendingStart,
            $pendingStart->addHour(),
        );

        $this->actingAs($user)
            ->post(route('crm.scheduling.appointments.store'), [
                'contact_id' => $contact->id,
                'bookable_service_id' => $pendingService->id,
                'scheduling_host_id' => null,
                'starts_at' => $pendingStart->toIso8601String(),
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect()
            ->assertSessionHas(
                'success',
                'Appointment created and awaiting confirmation.',
            );

        $appointment = Appointment::query()->sole();

        $this->assertSame(Appointment::STATUS_PENDING, $appointment->status);
        $this->assertNull($appointment->scheduling_host_id);
        $this->assertSame(
            AppointmentAttendee::STATUS_INVITED,
            $appointment->attendees()->sole()->status,
        );
    }

    public function test_workspace_rejects_forged_host_and_stale_time_without_creating_appointment(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        [$service, $assignedHost] = $this->hostedService();
        $otherHost = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => 'UTC',
        ]);
        $startsAt = CarbonImmutable::parse('2026-08-04 11:00:00 UTC');
        $this->availability(
            $service,
            $assignedHost,
            $startsAt,
            $startsAt->addHour(),
        );

        $this->actingAs($user)
            ->from(route('crm.scheduling.index'))
            ->post(route('crm.scheduling.appointments.store'), [
                'contact_id' => $contact->id,
                'bookable_service_id' => $service->id,
                'scheduling_host_id' => $otherHost->id,
                'starts_at' => $startsAt->toIso8601String(),
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('crm.scheduling.index'))
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(0, Appointment::query()->count());

        $this->actingAs($user)
            ->from(route('crm.scheduling.index'))
            ->post(route('crm.scheduling.appointments.store'), [
                'contact_id' => $contact->id,
                'bookable_service_id' => $service->id,
                'scheduling_host_id' => $assignedHost->id,
                'starts_at' => $startsAt->addHours(3)->toIso8601String(),
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('crm.scheduling.index'))
            ->assertSessionHasErrors('starts_at');

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_workspace_replay_is_idempotent_and_caller_authored_internal_fields_are_ignored(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $service = $this->service(['name' => 'Replay Safe Service']);
        $startsAt = CarbonImmutable::parse('2026-08-04 13:00:00 UTC');
        $this->availability($service, null, $startsAt, $startsAt->addHour());
        $key = (string) Str::uuid();
        $payload = [
            'contact_id' => $contact->id,
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => null,
            'starts_at' => $startsAt->toIso8601String(),
            'idempotency_key' => $key,
            'status' => Appointment::STATUS_COMPLETED,
            'ends_at' => $startsAt->addDays(10)->toIso8601String(),
            'capacity' => 999,
            'source' => 'forged',
        ];

        $this->actingAs($user)
            ->post(route('crm.scheduling.appointments.store'), $payload)
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('crm.scheduling.appointments.store'), $payload)
            ->assertRedirect();

        $appointment = Appointment::query()->sole();

        $this->assertSame(1, Appointment::query()->count());
        $this->assertSame(1, AppointmentAttendee::query()->count());
        $this->assertSame(1, $appointment->lifecycleEvents()->count());
        $this->assertSame(Appointment::STATUS_SCHEDULED, $appointment->status);
        $this->assertTrue($appointment->ends_at->equalTo($startsAt->addHour()));
        $this->assertSame('crm', $appointment->source);
    }

    private function enableScheduling(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
        ])));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function service(array $attributes = []): BookableService
    {
        return BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
            'capacity' => 1,
            ...$attributes,
        ]);
    }

    /**
     * @param array<string, mixed> $serviceAttributes
     * @return array{0: BookableService, 1: SchedulingHost}
     */
    private function hostedService(array $serviceAttributes = []): array
    {
        $service = $this->service($serviceAttributes);
        $host = SchedulingHost::factory()->create([
            'status' => SchedulingHost::STATUS_ACTIVE,
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);

        BookableServiceHost::factory()->create([
            'bookable_service_id' => $service->id,
            'scheduling_host_id' => $host->id,
            'is_active' => true,
        ]);

        return [$service, $host];
    }

    private function availability(
        BookableService $service,
        ?SchedulingHost $host,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): SchedulingAvailabilityWindow {
        $factory = SchedulingAvailabilityWindow::factory()
            ->absolute($startsAt, $endsAt);

        $factory = $host instanceof SchedulingHost
            ? $factory->forServiceAndHost($service, $host)
            : $factory->serviceWide($service);

        return $factory->create([
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);
    }
}
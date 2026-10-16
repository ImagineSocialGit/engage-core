<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Data\Contacts\ContactPanel;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchedulingContactPanelTest extends TestCase
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

    public function test_scheduling_registers_a_module_filtered_contact_panel(): void
    {
        $contact = Contact::factory()->create();
        $registry = app(ContactPanelRegistry::class);
        $panel = $registry->panelsFor($contact)->first(
            fn (ContactPanel $panel): bool => $panel->key === 'scheduling-appointments',
        );

        $this->assertNotNull(
            $this->app->getProvider(SchedulingModuleServiceProvider::class),
        );
        $this->assertInstanceOf(ContactPanel::class, $panel);
        $this->assertSame('crm.scheduling.contact-panel', $panel->view);
        $this->assertSame('scheduling', $panel->module);
        $this->assertSame(90, $panel->sort);
        $this->assertArrayHasKey('upcomingAppointments', $panel->data);
        $this->assertArrayHasKey('recentAppointments', $panel->data);
        $this->assertArrayHasKey('pendingAppointmentCount', $panel->data);

        config()->set('modules.enabled', ['core']);

        $this->assertFalse(
            $registry->panelsFor($contact)->contains(
                fn (ContactPanel $candidate): bool =>
                    $candidate->key === 'scheduling-appointments',
            ),
        );
    }

    public function test_panel_queries_are_bounded_ordered_contact_isolated_and_lineage_aware(): void
    {
        $contact = Contact::factory()->create();
        $otherContact = Contact::factory()->create();
        $service = $this->service(['name' => 'Planning Session']);
        $next = $this->appointment(
            contact: $contact,
            service: $service,
            title: 'Next Pending Session',
            status: Appointment::STATUS_PENDING,
            startsAt: CarbonImmutable::parse('2026-08-04 09:00:00 UTC'),
        );
        $later = $this->appointment(
            contact: $contact,
            service: $service,
            title: 'Later Confirmed Session',
            status: Appointment::STATUS_CONFIRMED,
            startsAt: CarbonImmutable::parse('2026-08-05 09:00:00 UTC'),
        );
        $completed = $this->appointment(
            contact: $contact,
            service: $service,
            title: 'Recent Completed Session',
            status: Appointment::STATUS_COMPLETED,
            startsAt: CarbonImmutable::parse('2026-08-02 09:00:00 UTC'),
        );
        $original = $this->appointment(
            contact: $contact,
            service: $service,
            title: 'Rescheduled Original Session',
            status: Appointment::STATUS_CANCELED,
            startsAt: CarbonImmutable::parse('2026-08-01 09:00:00 UTC'),
        );
        $replacement = $this->appointment(
            contact: $contact,
            service: $service,
            title: 'Replacement Session',
            status: Appointment::STATUS_SCHEDULED,
            startsAt: CarbonImmutable::parse('2026-08-06 09:00:00 UTC'),
            attributes: [
                'rescheduled_from_id' => $original->id,
            ],
        );
        $this->appointment(
            contact: $otherContact,
            service: $service,
            title: 'Other Contact Appointment',
            status: Appointment::STATUS_PENDING,
            startsAt: CarbonImmutable::parse('2026-08-04 08:00:00 UTC'),
        );

        $panel = app(ContactPanelRegistry::class)
            ->panelsFor($contact)
            ->firstWhere('key', 'scheduling-appointments');
        $upcoming = $panel->data['upcomingAppointments'];
        $recent = $panel->data['recentAppointments'];

        $this->assertCount(3, $upcoming);
        $this->assertEquals(
            [$next->id, $later->id, $replacement->id],
            $upcoming->modelKeys(),
        );
        $this->assertCount(2, $recent);
        $this->assertEquals(
            [$completed->id, $original->id],
            $recent->modelKeys(),
        );
        $this->assertSame(1, $panel->data['pendingAppointmentCount']);
        $this->assertTrue($replacement->is($upcoming->last()));
        $this->assertTrue($upcoming->last()->relationLoaded('rescheduledFrom'));
        $this->assertTrue($recent->last()->relationLoaded('rescheduledAppointments'));
        $this->assertTrue(
            $recent->last()->rescheduledAppointments->contains(
                fn (Appointment $appointment): bool => $appointment->is($replacement),
            ),
        );
    }


    public function test_workspace_validates_preserves_and_reuses_contact_preselection(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'name' => 'Jordan Example',
            'email' => 'jordan@example.test',
        ]);
        $service = $this->service(['name' => 'Preselected Service']);
        $startsAt = CarbonImmutable::parse('2026-08-04 13:00:00 UTC');
        $this->availability($service, $startsAt, $startsAt->addHour());

        $workspaceUrl = route('crm.scheduling.appointments.create', [
            'contact_id' => $contact->id,
            'bookable_service_id' => $service->id,
            'date' => '2026-08-04',
        ]);

        $this->actingAs($user)
            ->get($workspaceUrl)
            ->assertOk();

        $response = $this->actingAs($user)
            ->post(route('crm.scheduling.appointments.store'), [
                'attendee_mode' => 'contact',
                'contact_id' => $contact->id,
                'bookable_service_id' => $service->id,
                'scheduling_host_id' => null,
                'starts_at' => $startsAt->toIso8601String(),
                'idempotency_key' => (string) Str::uuid(),
            ]);

        $response
            ->assertRedirect()
            ->assertSessionHas('success');

        $location = (string) $response->headers->get('Location');

        $this->assertStringContainsString('contact_id='.$contact->id, $location);
        $this->assertStringContainsString(
            'bookable_service_id='.$service->id,
            $location,
        );
        $this->assertSame($contact->id, Appointment::query()->sole()->contact_id);
    }

    private function enableScheduling(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
        ])));

        if (! $this->app->getProvider(SchedulingModuleServiceProvider::class)) {
            $this->app->register(SchedulingModuleServiceProvider::class);
        }
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
     * @param array<string, mixed> $attributes
     */
    private function appointment(
        Contact $contact,
        BookableService $service,
        string $title,
        string $status,
        CarbonImmutable $startsAt,
        array $attributes = [],
    ): Appointment {
        return Appointment::factory()->create([
            'contact_id' => $contact->id,
            'bookable_service_id' => $service->id,
            'title' => $title,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
            'confirmed_at' => $status === Appointment::STATUS_CONFIRMED
                ? $startsAt->subDay()
                : null,
            'completed_at' => $status === Appointment::STATUS_COMPLETED
                ? $startsAt->addHour()
                : null,
            'canceled_at' => $status === Appointment::STATUS_CANCELED
                ? $startsAt->subHour()
                : null,
            'no_show_at' => $status === Appointment::STATUS_NO_SHOW
                ? $startsAt->addHour()
                : null,
            ...$attributes,
        ]);
    }

    private function availability(
        BookableService $service,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): SchedulingAvailabilityWindow {
        return SchedulingAvailabilityWindow::factory()
            ->absolute($startsAt, $endsAt)
            ->serviceWide($service)
            ->create([
                'timezone' => 'UTC',
                'capacity' => 1,
            ]);
    }
}
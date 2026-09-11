<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Services\Contacts\Filters\AppointmentStateContactFilterCriterion;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Services\Contacts\Filters\TaskStateContactFilterCriterion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactOperationalFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-09-11 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_task_filter_finds_contacts_with_open_or_overdue_tasks(): void
    {
        $openContact = Contact::factory()->create(['email' => 'open@example.test']);
        $overdueContact = Contact::factory()->create(['email' => 'overdue@example.test']);
        $completedContact = Contact::factory()->create(['email' => 'completed@example.test']);

        $this->taskFor($openContact, Task::STATUS_OPEN, CarbonImmutable::now()->addDay());
        $this->taskFor($overdueContact, Task::STATUS_OPEN, CarbonImmutable::now()->subHour());
        $this->taskFor($completedContact, Task::STATUS_COMPLETED, CarbonImmutable::now()->subHour());

        $criterion = app(TaskStateContactFilterCriterion::class);

        $openQuery = Contact::query()->orderBy('id');
        $criterion->apply($openQuery, ['open']);

        $this->assertSame(
            [$openContact->id, $overdueContact->id],
            $openQuery->pluck('contacts.id')->all(),
        );

        $overdueQuery = Contact::query()->orderBy('id');
        $criterion->apply($overdueQuery, ['overdue']);

        $this->assertSame(
            [$overdueContact->id],
            $overdueQuery->pluck('contacts.id')->all(),
        );
    }

    public function test_appointment_filter_finds_upcoming_and_pending_confirmation_contacts(): void
    {
        $scheduledContact = Contact::factory()->create(['email' => 'scheduled@example.test']);
        $pendingContact = Contact::factory()->create(['email' => 'pending@example.test']);
        $pastContact = Contact::factory()->create(['email' => 'past@example.test']);
        $service = BookableService::factory()->create([
            'status' => BookableService::STATUS_ACTIVE,
            'duration_minutes' => 60,
            'slot_interval_minutes' => 60,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 30,
            'timezone' => 'UTC',
            'capacity' => 1,
        ]);

        $this->appointmentFor(
            contact: $scheduledContact,
            service: $service,
            status: Appointment::STATUS_SCHEDULED,
            startsAt: CarbonImmutable::now()->addDay(),
        );
        $this->appointmentFor(
            contact: $pendingContact,
            service: $service,
            status: Appointment::STATUS_PENDING,
            startsAt: CarbonImmutable::now()->addHours(2),
        );
        $this->appointmentFor(
            contact: $pastContact,
            service: $service,
            status: Appointment::STATUS_COMPLETED,
            startsAt: CarbonImmutable::now()->subDay(),
        );

        $criterion = app(AppointmentStateContactFilterCriterion::class);

        $upcomingQuery = Contact::query()->orderBy('id');
        $criterion->apply($upcomingQuery, ['upcoming']);

        $this->assertSame(
            [$scheduledContact->id, $pendingContact->id],
            $upcomingQuery->pluck('contacts.id')->all(),
        );

        $pendingQuery = Contact::query()->orderBy('id');
        $criterion->apply($pendingQuery, ['awaiting_confirmation']);

        $this->assertSame(
            [$pendingContact->id],
            $pendingQuery->pluck('contacts.id')->all(),
        );
    }

    private function taskFor(Contact $contact, string $status, CarbonImmutable $dueAt): Task
    {
        $task = Task::factory()->create([
            'status' => $status,
            'due_at' => $dueAt,
            'archived_at' => null,
        ]);

        TaskLink::query()->create([
            'task_id' => $task->id,
            'linkable_type' => Contact::class,
            'linkable_id' => $contact->id,
            'role' => TaskLink::ROLE_SUBJECT,
        ]);

        return $task;
    }

    private function appointmentFor(
        Contact $contact,
        BookableService $service,
        string $status,
        CarbonImmutable $startsAt,
    ): Appointment {
        return Appointment::factory()->create([
            'contact_id' => $contact->id,
            'bookable_service_id' => $service->id,
            'status' => $status,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->addHour(),
        ]);
    }
}
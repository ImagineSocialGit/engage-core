<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\ModuleIntegrations\Scheduling\Automation\CreateAppointmentTaskAutomationActionHandler;
use App\Support\ModuleIntegrations\Scheduling\Automation\ReconcileAppointmentTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentTaskAutomationRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_is_host_assigned_and_follows_reschedule_and_cancellation(): void
    {
        $contact = Contact::factory()->create();
        $hostMember = TeamMember::factory()->create();
        $host = SchedulingHost::factory()->create([
            'hostable_type' => $hostMember->getMorphClass(),
            'hostable_id' => $hostMember->getKey(),
        ]);
        $appointment = Appointment::factory()->create([
            'contact_id' => $contact->getKey(),
            'scheduling_host_id' => $host->getKey(),
            'starts_at' => now()->addDays(4)->startOfHour(),
        ]);
        $template = TaskTemplate::factory()->currentSubject()->create([
            'key' => 'appointment.prep',
            'title' => 'Prepare for appointment',
        ]);

        $result = app(CreateAppointmentTaskAutomationActionHandler::class)->handle(new AutomationActionContext(
            input: [
                'task_template_key' => $template->key,
                'offset_minutes' => -1440,
                'assign_to_host' => true,
            ],
            subject: $appointment,
            models: ['current_subject' => $appointment, 'current_contact' => $contact],
        ));

        $this->assertSame('completed', $result->status);
        $task = Task::query()->firstOrFail();
        $this->assertSame($hostMember->getMorphClass(), $task->assigned_to_type);
        $this->assertSame($hostMember->getKey(), $task->assigned_to_id);
        $this->assertTrue($task->due_at->equalTo($appointment->starts_at->copy()->subDay()));

        $replacement = Appointment::factory()->create([
            'contact_id' => $contact->getKey(),
            'scheduling_host_id' => $host->getKey(),
            'rescheduled_from_id' => $appointment->getKey(),
            'starts_at' => now()->addDays(7)->startOfHour(),
        ]);

        app(ReconcileAppointmentTasks::class)->handle(new AutomationEventRecorded(
            AutomationEventData::forSubject(
                eventKey: 'appointment.rescheduled',
                subject: $replacement,
                payload: [
                    'original_appointment_id' => $appointment->getKey(),
                    'replacement_appointment_id' => $replacement->getKey(),
                ],
            ),
        ));

        $task->refresh();
        $this->assertTrue($task->due_at->equalTo($replacement->starts_at->copy()->subDay()));
        $this->assertDatabaseHas('task_links', [
            'task_id' => $task->getKey(),
            'linkable_id' => $replacement->getKey(),
            'role' => TaskLink::ROLE_SUBJECT,
        ]);

        app(ReconcileAppointmentTasks::class)->handle(new AutomationEventRecorded(
            AutomationEventData::forSubject(
                eventKey: 'appointment.canceled',
                subject: $replacement,
                payload: ['appointment_id' => $replacement->getKey()],
            ),
        ));

        $this->assertSame(Task::STATUS_CANCELED, $task->refresh()->status);
    }
}
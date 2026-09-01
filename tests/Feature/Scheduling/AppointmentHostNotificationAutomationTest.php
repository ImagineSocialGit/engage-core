<?php

namespace Tests\Feature\Scheduling;

use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\ModuleIntegrations\Scheduling\Automation\AppointmentHostNotificationScheduler;
use App\Support\ModuleIntegrations\Scheduling\Automation\NotifyAppointmentHostAutomationActionHandler;
use App\Support\ModuleIntegrations\Scheduling\Automation\ReconcileAppointmentHostNotifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentHostNotificationAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_is_scheduled_for_host_relative_to_start(): void
    {
        $teamMember = TeamMember::factory()->create(['email' => 'host@example.com', 'is_active' => true]);
        $host = SchedulingHost::factory()->create([
            'hostable_type' => $teamMember->getMorphClass(),
            'hostable_id' => $teamMember->getKey(),
        ]);
        $appointment = Appointment::factory()->create([
            'scheduling_host_id' => $host->getKey(),
            'starts_at' => now()->addDays(3)->startOfHour(),
        ]);

        $this->mock(ScheduleInternalNotificationAction::class)
            ->shouldReceive('handle')
            ->once()
            ->withArgs(function (...$arguments) use ($appointment, $teamMember): bool {
                $recipient = $arguments['recipient'] ?? $arguments[0];
                $sendAt = $arguments['sendAt'] ?? $arguments[5];

                return $recipient->source->is($teamMember)
                    && $sendAt->equalTo($appointment->starts_at->copy()->subDay());
            })
            ->andReturnUsing(fn (): ScheduledMessage => ScheduledMessage::factory()->create([
                'recipient_type' => $teamMember->getMorphClass(),
                'recipient_id' => $teamMember->getKey(),
                'context_type' => $appointment->getMorphClass(),
                'context_id' => $appointment->getKey(),
                'purpose' => 'internal',
                'scope' => 'appointment_host_reminders',
                'message_type' => 'appointment_host_reminder',
                'send_at' => $appointment->starts_at->copy()->subDay(),
            ]));

        $result = app(NotifyAppointmentHostAutomationActionHandler::class)->handle(new AutomationActionContext(
            input: [
                'offset_minutes' => -1440,
                'subject' => 'Prepare for appointment',
                'message' => 'Review the file.',
            ],
            subject: $appointment,
        ));

        $this->assertSame('completed', $result->status);
        $this->assertSame('appointment_host_notification_scheduled', $result->reason);
    }

    public function test_reschedule_skips_old_pending_notification_and_schedules_replacement(): void
    {
        $original = Appointment::factory()->create(['starts_at' => now()->addDays(2)]);
        $replacement = Appointment::factory()->create([
            'rescheduled_from_id' => $original->getKey(),
            'starts_at' => now()->addDays(5),
        ]);
        $message = ScheduledMessage::factory()->create([
            'context_type' => $original->getMorphClass(),
            'context_id' => $original->getKey(),
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'appointment_automation' => [
                    'kind' => 'host_notification',
                    'flow_route_point_id' => 41,
                    'definition' => [
                        'offset_minutes' => -60,
                        'subject' => 'Prepare',
                        'message' => 'Review.',
                    ],
                ],
            ],
        ]);

        $this->mock(AppointmentHostNotificationScheduler::class)
            ->shouldReceive('schedule')
            ->once()
            ->withArgs(fn (Appointment $appointment, array $definition, ?int $pointId): bool => $appointment->is($replacement)
                && $definition['offset_minutes'] === -60
                && $pointId === 41)
            ->andReturn(null);

        app(ReconcileAppointmentHostNotifications::class)->handle(new AutomationEventRecorded(
            AutomationEventData::forSubject(
                eventKey: 'appointment.rescheduled',
                subject: $replacement,
                payload: [
                    'original_appointment_id' => $original->getKey(),
                    'replacement_appointment_id' => $replacement->getKey(),
                ],
            ),
        ));

        $this->assertSame(ScheduledMessage::STATUS_SKIPPED, $message->refresh()->status);
    }
}
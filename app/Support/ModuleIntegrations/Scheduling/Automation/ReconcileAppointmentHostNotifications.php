<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;

class ReconcileAppointmentHostNotifications
{
    public function __construct(
        private readonly SkipScheduledMessagesAction $skipMessages,
        private readonly AppointmentHostNotificationScheduler $scheduler,
    ) {}

    public function handle(AutomationEventRecorded $recorded): void
    {
        if ($recorded->event->eventKey === 'appointment.rescheduled') {
            $this->rebase(
                (int) ($recorded->event->payload['original_appointment_id'] ?? 0),
                (int) ($recorded->event->payload['replacement_appointment_id'] ?? 0),
            );
        }

        if ($recorded->event->eventKey === 'appointment.canceled') {
            $appointment = Appointment::query()->find((int) ($recorded->event->payload['appointment_id'] ?? 0));

            if ($appointment instanceof Appointment) {
                $this->skipMessages->forContextMetaValue(
                    context: $appointment,
                    key: 'appointment_automation->kind',
                    value: 'host_notification',
                    reason: 'appointment_canceled',
                );
            }
        }
    }

    private function rebase(int $originalId, int $replacementId): void
    {
        $original = Appointment::query()->find($originalId);
        $replacement = Appointment::query()->find($replacementId);

        if (! $original instanceof Appointment || ! $replacement instanceof Appointment) {
            return;
        }

        $messages = ScheduledMessage::query()
            ->where('context_type', $original->getMorphClass())
            ->where('context_id', $original->getKey())
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->where('meta->appointment_automation->kind', 'host_notification')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        $this->skipMessages->forContextMetaValue(
            context: $original,
            key: 'appointment_automation->kind',
            value: 'host_notification',
            reason: 'appointment_rescheduled',
        );

        foreach ($messages as $message) {
            $definition = data_get($message->meta, 'appointment_automation.definition');

            if (! is_array($definition)) {
                continue;
            }

            $pointId = data_get($message->meta, 'appointment_automation.flow_route_point_id');

            $this->scheduler->schedule(
                appointment: $replacement,
                definition: $definition,
                flowRoutePointId: is_numeric($pointId) ? (int) $pointId : null,
            );
        }
    }
}
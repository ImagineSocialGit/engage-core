<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Modules\Scheduling\Models\Appointment;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Throwable;

class NotifyAppointmentHostAutomationActionHandler implements AutomationActionHandler
{
    public function __construct(
        private readonly AppointmentHostNotificationScheduler $scheduler,
    ) {}

    public function key(): string
    {
        return 'scheduling.notify_appointment_host';
    }

    public function handle(AutomationActionContext $context): AutomationActionResult
    {
        if (! $context->subject instanceof Appointment) {
            return AutomationActionResult::failed('appointment_host_notification_requires_appointment_subject');
        }

        try {
            $message = $this->scheduler->schedule(
                appointment: $context->subject,
                definition: $context->input,
                flowRoutePointId: is_numeric($context->behaviorOwner?->getKey())
                    ? (int) $context->behaviorOwner->getKey()
                    : null,
            );
        } catch (Throwable $exception) {
            return AutomationActionResult::failed('appointment_host_notification_failed', output: [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }

        if ($message === null) {
            return AutomationActionResult::blocked('appointment_host_notification_recipient_unavailable');
        }

        return AutomationActionResult::completed(
            reason: 'appointment_host_notification_scheduled',
            artifacts: [$message],
            correlationKey: 'scheduled_message.id',
            correlationType: 'scheduled_message',
            correlation: ['scheduled_message_id' => $message->getKey()],
            output: ['scheduled_message_id' => $message->getKey(), 'send_at' => $message->send_at?->toISOString()],
        );
    }
}
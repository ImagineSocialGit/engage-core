<?php

namespace App\Support\ModuleIntegrations\Scheduling\Automation;

use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Scheduling\Models\Appointment;
use Illuminate\Support\Carbon;

class AppointmentHostNotificationScheduler
{
    public const NOTIFICATION_TYPE = 'appointment_host_reminder';

    public function __construct(
        private readonly ScheduleInternalNotificationAction $scheduleInternalNotification,
    ) {}

    public function schedule(Appointment $appointment, array $definition, ?int $flowRoutePointId): ?ScheduledMessage
    {
        $appointment->loadMissing(['schedulingHost.hostable', 'contact']);
        $host = $appointment->schedulingHost?->hostable;

        if (! $host instanceof TeamMember || ! $host->is_active || $appointment->starts_at === null) {
            return null;
        }

        $offsetMinutes = (int) ($definition['offset_minutes'] ?? 0);
        $subject = trim((string) ($definition['subject'] ?? 'Upcoming appointment'));
        $message = trim((string) ($definition['message'] ?? 'Please review the appointment details.'));
        $startsAt = Carbon::instance($appointment->starts_at)->utc();
        $sendAt = $startsAt->addMinutes($offsetMinutes);

        if ($sendAt->isPast()) {
            $sendAt = Carbon::now('UTC');
        }

        $localStartsAt = $startsAt
            ->timezone(config('client.timezone', config('app.timezone', 'UTC')))
            ->format('M j, Y g:i A T');
        $contactName = trim((string) ($appointment->contact?->name
            ?: trim((string) $appointment->contact?->first_name.' '.(string) $appointment->contact?->last_name)));

        return $this->scheduleInternalNotification->handle(
            recipient: new InternalNotificationRecipient(
                source: $host,
                name: trim((string) $host->name) ?: ($host->email ?: 'Appointment host'),
                email: $host->email,
                phone: $host->phone,
                notificationType: self::NOTIFICATION_TYPE,
                preferenceOwner: $host,
            ),
            scope: 'appointment_host_reminders',
            messageType: self::NOTIFICATION_TYPE,
            content: [
                'subject' => $subject,
                'headline' => $subject,
                'preheader' => 'An appointment needs your attention.',
                'body' => [$message],
                'details' => [
                    'Appointment' => $appointment->title ?: 'Appointment #'.$appointment->getKey(),
                    'Contact' => $contactName !== '' ? $contactName : '—',
                    'Starts' => $localStartsAt,
                ],
                'cta' => [
                    'label' => 'Open appointment',
                    'url' => route('crm.scheduling.appointments.show', $appointment),
                ],
                'sms_message' => $message.' Appointment: '.($appointment->title ?: '#'.$appointment->getKey()).'. Starts '.$localStartsAt.'.',
                'meta' => ['appointment_id' => $appointment->getKey()],
            ],
            context: $appointment,
            sendAt: $sendAt,
            dedupeKey: $this->dedupeKey($appointment, $flowRoutePointId, $definition),
            meta: [
                'appointment_automation' => [
                    'kind' => 'host_notification',
                    'anchor' => 'appointment_start',
                    'appointment_id' => (int) $appointment->getKey(),
                    'offset_minutes' => $offsetMinutes,
                    'flow_route_point_id' => $flowRoutePointId,
                    'definition' => [
                        'offset_minutes' => $offsetMinutes,
                        'subject' => $subject,
                        'message' => $message,
                    ],
                ],
            ],
        );
    }

    private function dedupeKey(Appointment $appointment, ?int $flowRoutePointId, array $definition): string
    {
        $identity = $flowRoutePointId !== null
            ? (string) $flowRoutePointId
            : substr(hash('sha256', json_encode($definition) ?: ''), 0, 20);

        return 'appointment_host_notification:'.$identity.':'.$appointment->getKey();
    }
}
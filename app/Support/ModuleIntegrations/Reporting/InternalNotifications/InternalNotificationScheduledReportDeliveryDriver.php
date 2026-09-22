<?php

namespace App\Support\ModuleIntegrations\Reporting\InternalNotifications;

use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Models\TeamMemberNotificationPreference;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Reporting\Contracts\ScheduledReportDeliveryDriver;
use App\Modules\Reporting\Data\ScheduledReportResult;
use App\Modules\Reporting\Models\ScheduledReportSubscription;
use App\Modules\Reporting\Models\ScheduledReportSubscriptionRecipient;

final class InternalNotificationScheduledReportDeliveryDriver implements ScheduledReportDeliveryDriver
{
    public function __construct(
        private readonly ScheduleInternalNotificationAction $schedule,
    ) {}

    public function supports(string $recipientType, string $channel): bool
    {
        $teamMember = new TeamMember();

        return $channel === MessageChannel::Email->value
            && in_array($recipientType, [
                TeamMember::class,
                $teamMember->getMorphClass(),
            ], true);
    }

    public function deliver(
        ScheduledReportSubscription $subscription,
        ScheduledReportSubscriptionRecipient $recipient,
        ScheduledReportResult $result,
        string $occurrenceKey,
    ): bool {
        $teamMember = TeamMember::query()
            ->with('notificationPreferences')
            ->find($recipient->recipient_id);

        if (! $teamMember instanceof TeamMember
            || ! $teamMember->is_active
            || ! $teamMember->email
        ) {
            return false;
        }

        $notificationRecipient = new InternalNotificationRecipient(
            source: $teamMember,
            name: trim((string) $teamMember->name) !== ''
                ? (string) $teamMember->name
                : (string) $teamMember->email,
            email: $teamMember->email,
            phone: $teamMember->phone,
            notificationType: TeamMemberNotificationPreference::TYPE_SCHEDULED_REPORT,
            preferenceOwner: $teamMember,
        );

        return $this->schedule->handle(
            recipient: $notificationRecipient,
            scope: 'scheduled_reports',
            messageType: 'scheduled_report',
            content: $result->internalNotificationContent(),
            sendAt: now(),
            dedupeKey: implode(':', [
                'scheduled_report',
                $subscription->uuid,
                $recipient->recipient_type,
                $recipient->recipient_id,
                $occurrenceKey,
            ]),
            meta: [
                'surface' => 'scheduled_reports',
                'scheduled_report_uuid' => $subscription->uuid,
                'scheduled_report_key' => $subscription->report_key,
                'scheduled_report_name' => $subscription->name,
                'occurrence_key' => $occurrenceKey,
            ],
            allowedChannels: [MessageChannel::Email],
        ) !== null;
    }
}
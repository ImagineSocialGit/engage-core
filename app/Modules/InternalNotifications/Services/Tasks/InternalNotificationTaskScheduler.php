<?php

namespace App\Modules\InternalNotifications\Services\Tasks;

use App\Modules\InternalNotifications\Actions\ScheduleInternalNotificationAction;
use App\Modules\InternalNotifications\Services\InternalNotificationRecipient;
use App\Modules\Tasks\Contracts\TaskNotificationSchedulerContract;
use App\Modules\Tasks\Data\TaskNotification;

class InternalNotificationTaskScheduler implements TaskNotificationSchedulerContract
{
    public function __construct(
        private readonly ScheduleInternalNotificationAction $scheduleInternalNotification,
    ) {}

    public function supports(TaskNotification $notification): bool
    {
        return true;
    }

    public function schedule(TaskNotification $notification): bool
    {
        $recipient = $notification->recipient;

        return $this->scheduleInternalNotification->handle(
            recipient: new InternalNotificationRecipient(
                source: $recipient->source,
                name: $recipient->name,
                email: $recipient->email,
                phone: $recipient->phone,
                notificationType: $notification->notificationType,
                preferenceOwner: $recipient->preferenceOwner,
            ),
            scope: $notification->scope,
            messageType: $notification->messageType,
            content: $notification->content,
            context: $notification->context,
            dedupeKey: $notification->dedupeKey,
            meta: $notification->meta,
        ) !== null;
    }
}

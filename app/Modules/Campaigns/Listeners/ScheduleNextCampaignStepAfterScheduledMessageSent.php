<?php

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Campaigns\Actions\ScheduleNextCampaignStepAction;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Campaigns\Models\CampaignEnrollment;

class ScheduleNextCampaignStepAfterScheduledMessageSent
{
    public function __construct(
        private readonly ScheduleNextCampaignStepAction $scheduleNextCampaignStepAction,
    ) {}

    public function handle(ScheduledMessageSent $event): void
    {
        $scheduledMessage = $event->scheduledMessage;

        $campaignEnrollmentId = $scheduledMessage->meta['campaign_enrollment_id'] ?? null;

        if (! is_numeric($campaignEnrollmentId)) {
            return;
        }

        $enrollment = CampaignEnrollment::query()->find((int) $campaignEnrollmentId);

        if (! $enrollment) {
            return;
        }

        $this->scheduleNextCampaignStepAction->handle(
            enrollment: $enrollment,
            dispatchKey: 'marketing_message_sent',
            context: $scheduledMessage->context,
            payload: [],
            meta: [
                'previous_scheduled_message_id' => $scheduledMessage->id,
            ],
        );
    }
}
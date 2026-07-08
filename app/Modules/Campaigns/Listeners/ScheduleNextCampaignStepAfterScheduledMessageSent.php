<?php

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Campaigns\Actions\ScheduleNextCampaignStepAction;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ScheduledMessage;

class ScheduleNextCampaignStepAfterScheduledMessageSent
{
    public function __construct(
        private readonly ScheduleNextCampaignStepAction $scheduleNextCampaignStepAction,
    ) {}

    public function handle(ScheduledMessageSent|ScheduledMessageSkipped $event): void
    {
        $scheduledMessage = $event->scheduledMessage;

        $campaignEnrollmentId = $scheduledMessage->meta['campaign_enrollment_id'] ?? null;

        if (! is_numeric($campaignEnrollmentId)) {
            return;
        }

        if ($this->hasPendingSiblingVariantMessages($scheduledMessage, (int) $campaignEnrollmentId)) {
            return;
        }

        $enrollment = CampaignEnrollment::query()->find((int) $campaignEnrollmentId);

        if (! $enrollment) {
            return;
        }

        $campaignStepId = $scheduledMessage->meta['campaign_step_id'] ?? null;

        if (
            is_numeric($campaignStepId)
            && (int) $enrollment->current_campaign_step_id !== (int) $campaignStepId
        ) {
            return;
        }

        $this->scheduleNextCampaignStepAction->handle(
            enrollment: $enrollment,
            dispatchKey: null,
            context: $scheduledMessage->context,
            payload: [],
            meta: [
                'previous_scheduled_message_id' => $scheduledMessage->id,
            ],
        );
    }

    private function hasPendingSiblingVariantMessages(ScheduledMessage $scheduledMessage, int $campaignEnrollmentId): bool
    {
        if (! (bool) ($scheduledMessage->meta['campaign_step_waits_for_all_scheduled_variants'] ?? false)) {
            return false;
        }

        $campaignStepId = $scheduledMessage->meta['campaign_step_id'] ?? null;

        if (! is_numeric($campaignStepId)) {
            return false;
        }

        return ScheduledMessage::query()
            ->whereKeyNot($scheduledMessage->getKey())
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->where('meta->campaign_enrollment_id', $campaignEnrollmentId)
            ->where('meta->campaign_step_id', (int) $campaignStepId)
            ->exists();
    }
}

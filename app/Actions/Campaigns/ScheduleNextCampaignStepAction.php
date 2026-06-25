<?php

namespace App\Actions\Campaigns;

use App\Actions\Messaging\DispatchMessageAction;
use App\Models\CampaignEnrollment;
use App\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ScheduleNextCampaignStepAction
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     */
    public function handle(
        CampaignEnrollment $enrollment,
        string $dispatchKey = 'marketing_message_sent',
        ?Model $context = null,
        array $payload = [],
        ?array $meta = null,
    ): ?ScheduledMessage {
        if (! $enrollment->isActive()) {
            return null;
        }

        $enrollment->loadMissing('contact');

        if (! $enrollment->contact) {
            $this->completeEnrollment(
                enrollment: $enrollment,
                reason: CampaignEnrollment::EXIT_REASON_CONDITION_MATCHED,
            );

            return null;
        }

        $nextStep = ((int) $enrollment->current_step) + 1;

        $scheduledMessages = $this->dispatchMessageAction->handle(
            recipient: $enrollment->contact,
            channel: $enrollment->channel,
            purpose: $enrollment->purpose,
            scope: $enrollment->scope,
            dispatchKeys: $dispatchKey,
            payload: $payload,
            context: $context,
            meta: array_merge([
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_key' => $enrollment->campaign_key,
                'campaign_step' => $nextStep,
            ], $meta ?? []),
            criteria: [
                'campaign_key' => $enrollment->campaign_key,
                'step' => $nextStep,
            ],
        );

        if ($scheduledMessages === []) {
            $this->completeEnrollment(
                enrollment: $enrollment,
                reason: CampaignEnrollment::EXIT_REASON_NO_NEXT_STEP,
            );

            return null;
        }

        $scheduledMessage = $scheduledMessages[0];

        $enrollment->forceFill([
            'current_step' => $nextStep,
            'last_scheduled_message_id' => $scheduledMessage->id,
        ])->save();

        return $scheduledMessage;
    }

    private function completeEnrollment(CampaignEnrollment $enrollment, string $reason): void
    {
        $now = Carbon::now();

        $enrollment->forceFill([
            'status' => CampaignEnrollment::STATUS_COMPLETED,
            'completed_at' => $enrollment->completed_at ?? $now,
            'exited_at' => $enrollment->exited_at ?? $now,
            'exit_reason' => $enrollment->exit_reason ?? $reason,
        ])->save();
    }
}
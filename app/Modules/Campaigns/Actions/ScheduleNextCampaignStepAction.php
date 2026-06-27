<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
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
        ?string $dispatchKey = null,
        ?Model $context = null,
        array $payload = [],
        ?array $meta = null,
    ): ?ScheduledMessage {
        if (! $enrollment->isActive()) {
            return null;
        }

        $enrollment->loadMissing([
            'campaign.activeSteps',
            'contact',
        ]);

        if (! $enrollment->contact) {
            $this->completeEnrollment(
                enrollment: $enrollment,
                reason: CampaignEnrollment::EXIT_REASON_CONDITION_MATCHED,
            );

            return null;
        }

        $campaign = $enrollment->campaign;

        if (! $campaign instanceof Campaign || ! $campaign->isActive()) {
            $this->completeEnrollment(
                enrollment: $enrollment,
                reason: CampaignEnrollment::EXIT_REASON_NO_NEXT_STEP,
            );

            return null;
        }

        $nextStep = $this->nextStep($campaign, $enrollment, $dispatchKey);

        if (! $nextStep instanceof CampaignStep) {
            $this->completeEnrollment(
                enrollment: $enrollment,
                reason: CampaignEnrollment::EXIT_REASON_NO_NEXT_STEP,
            );

            return null;
        }

        $scheduledMessage = $this->scheduleStep(
            enrollment: $enrollment,
            campaign: $campaign,
            step: $nextStep,
            context: $context,
            payload: $payload,
            meta: $meta,
        );

        if (! $scheduledMessage instanceof ScheduledMessage) {
            $this->completeEnrollment(
                enrollment: $enrollment,
                reason: CampaignEnrollment::EXIT_REASON_NO_NEXT_STEP,
            );

            return null;
        }

        $enrollment->forceFill([
            'current_step' => $nextStep->step_number,
            'current_campaign_step_id' => $nextStep->id,
            'last_scheduled_message_id' => $scheduledMessage->id,
        ])->save();

        return $scheduledMessage;
    }

    private function nextStep(
        Campaign $campaign,
        CampaignEnrollment $enrollment,
        ?string $dispatchKey,
    ): ?CampaignStep {
        $query = $campaign->activeSteps()
            ->where('step_number', '>', (int) $enrollment->current_step)
            ->orderBy('step_number');

        if ($dispatchKey !== null) {
            $query->where('dispatch_key', $dispatchKey);
        }

        return $query->first();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     */
    private function scheduleStep(
        CampaignEnrollment $enrollment,
        Campaign $campaign,
        CampaignStep $step,
        ?Model $context,
        array $payload,
        ?array $meta,
    ): ?ScheduledMessage {
        $scheduledMessages = $this->dispatchMessageAction->handle(
            recipient: $enrollment->contact,
            channel: $campaign->channel,
            purpose: $campaign->purpose,
            scope: $campaign->scope,
            dispatchKeys: $step->dispatch_key,
            payload: array_replace_recursive($step->payload ?? [], $payload),
            context: $context,
            meta: array_replace_recursive([
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_id' => $campaign->id,
                'campaign_key' => $campaign->key,
                'campaign_step_id' => $step->id,
                'campaign_step' => $step->step_number,
            ], $step->meta ?? [], $meta ?? []),
            criteria: array_replace_recursive([
                'campaign_key' => $campaign->key,
                'step' => $step->step_number,
            ], $step->criteria ?? []),
        );

        return $scheduledMessages[0] ?? null;
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
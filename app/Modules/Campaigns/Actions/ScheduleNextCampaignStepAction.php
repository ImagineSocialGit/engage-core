<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ScheduleNextCampaignStepAction
{
    public function __construct(
        private readonly ScheduleCampaignStepMessagesAction $scheduleCampaignStepMessagesAction,
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

        $scheduledMessage = $this->scheduleNextSchedulableStep(
            enrollment: $enrollment,
            campaign: $campaign,
            context: $context,
            payload: $payload,
            meta: $meta,
            dispatchKey: $dispatchKey,
        );

        if (! $scheduledMessage instanceof ScheduledMessage) {
            $this->completeEnrollment(
                enrollment: $enrollment,
                reason: CampaignEnrollment::EXIT_REASON_NO_NEXT_STEP,
            );

            return null;
        }

        return $scheduledMessage;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     */
    private function scheduleNextSchedulableStep(
        CampaignEnrollment $enrollment,
        Campaign $campaign,
        ?Model $context,
        array $payload,
        ?array $meta,
        ?string $dispatchKey,
    ): ?ScheduledMessage {
        while ($enrollment->isActive()) {
            $step = $this->nextStep(
                campaign: $campaign,
                enrollment: $enrollment,
                dispatchKey: $dispatchKey,
            );

            if (! $step instanceof CampaignStep) {
                return null;
            }

            if ($this->stepType($step) !== 'message') {
                $this->markStepSkipped(
                    enrollment: $enrollment,
                    step: $step,
                    reason: 'unsupported_campaign_step_type',
                );

                continue;
            }

            $scheduledMessage = $this->scheduleCampaignStepMessagesAction->handle(
                enrollment: $enrollment,
                campaign: $campaign,
                step: $step,
                contact: $enrollment->contact,
                context: $context,
                payload: $payload,
                meta: $meta,
            );

            if ($scheduledMessage instanceof ScheduledMessage) {
                $enrollment->forceFill([
                    'current_step' => $step->step_number,
                    'current_campaign_step_id' => $step->id,
                    'last_scheduled_message_id' => $scheduledMessage->id,
                ])->save();

                return $scheduledMessage;
            }

            $this->markStepSkipped(
                enrollment: $enrollment,
                step: $step,
                reason: 'message_not_scheduled',
            );
        }

        return null;
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
            $query->where('dispatch_key', $this->normalizeSegment($dispatchKey));
        }

        return $query->first();
    }

    private function stepType(CampaignStep $step): string
    {
        $type = data_get($step->meta ?? [], 'type', 'message');

        return is_string($type) && trim($type) !== ''
            ? $this->normalizeSegment($type)
            : 'message';
    }

    private function markStepSkipped(
        CampaignEnrollment $enrollment,
        CampaignStep $step,
        string $reason,
    ): void {
        $meta = $enrollment->meta ?? [];
        $skippedSteps = is_array($meta['skipped_steps'] ?? null) ? $meta['skipped_steps'] : [];

        $skippedSteps[] = [
            'campaign_step_id' => $step->id,
            'step' => $step->step_number,
            'reason' => $reason,
            'last_message_schedule_attempt' => $meta['last_message_schedule_attempt'] ?? null,
            'skipped_at' => now()->toISOString(),
        ];

        $meta['skipped_steps'] = $skippedSteps;

        $enrollment->forceFill([
            'current_step' => $step->step_number,
            'current_campaign_step_id' => $step->id,
            'meta' => $meta,
        ])->save();
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

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}

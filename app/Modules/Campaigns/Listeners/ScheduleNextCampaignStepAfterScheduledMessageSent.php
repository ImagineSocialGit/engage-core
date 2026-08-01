<?php

namespace App\Modules\Campaigns\Listeners;

use App\Modules\Campaigns\Actions\ScheduleCampaignStepMessagesAction;
use App\Modules\Campaigns\Actions\ScheduleNextCampaignStepAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Events\ScheduledMessageFailed;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Support\Facades\DB;

class ScheduleNextCampaignStepAfterScheduledMessageSent
{
    private const TERMINAL_FAILURE_POLICY = 'skip_failed_variant_after_all_scheduled_variants_terminal';

    public function __construct(
        private readonly ScheduleCampaignStepMessagesAction $scheduleCampaignStepMessagesAction,
        private readonly ScheduleNextCampaignStepAction $scheduleNextCampaignStepAction,
    ) {}

    public function handle(
        ScheduledMessageSent|ScheduledMessageSkipped|ScheduledMessageFailed $event,
    ): void {
        $scheduledMessage = $event->scheduledMessage;
        $terminalResult = $event->terminalResult;

        $campaignEnrollmentId = $scheduledMessage->meta['campaign_enrollment_id'] ?? null;

        if (! is_numeric($campaignEnrollmentId)) {
            return;
        }

        DB::transaction(function () use (
            $scheduledMessage,
            $terminalResult,
            $campaignEnrollmentId,
        ): void {
            $enrollment = CampaignEnrollment::query()
                ->lockForUpdate()
                ->find((int) $campaignEnrollmentId);

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

            $this->reevaluateCurrentDependencyAwareStep(
                scheduledMessage: $scheduledMessage,
                enrollment: $enrollment,
                campaignStepId: $campaignStepId,
            );

            $hasNonTerminalSiblings = $this->hasNonTerminalSiblingVariantMessages(
                scheduledMessage: $scheduledMessage,
                campaignEnrollmentId: (int) $campaignEnrollmentId,
            );

            $this->recordTerminalFailurePolicy(
                enrollment: $enrollment,
                scheduledMessage: $scheduledMessage,
                campaignEnrollmentId: (int) $campaignEnrollmentId,
                campaignStepId: $campaignStepId,
                hasNonTerminalSiblings: $hasNonTerminalSiblings,
                currentTerminalResult: $terminalResult,
            );

            if ($hasNonTerminalSiblings) {
                return;
            }

            $this->scheduleNextCampaignStepAction->handle(
                enrollment: $enrollment,
                dispatchKey: null,
                context: $scheduledMessage->context,
                payload: [],
                meta: [
                    'previous_scheduled_message_id' => $scheduledMessage->id,
                    'previous_scheduled_message_status' => $scheduledMessage->status,
                ],
            );
        });
    }

    private function reevaluateCurrentDependencyAwareStep(
        ScheduledMessage $scheduledMessage,
        CampaignEnrollment $enrollment,
        mixed $campaignStepId,
    ): void {
        if (! is_numeric($campaignStepId)) {
            return;
        }

        $step = CampaignStep::query()
            ->with('variants')
            ->find((int) $campaignStepId);

        if (! $step || $step->variant_strategy !== 'dependency_aware') {
            return;
        }

        $campaign = Campaign::query()->find($enrollment->campaign_id);
        $contact = Contact::query()->find($enrollment->contact_id);

        if (! $campaign || ! $contact) {
            return;
        }

        $this->scheduleCampaignStepMessagesAction->handle(
            enrollment: $enrollment,
            campaign: $campaign,
            step: $step,
            contact: $contact,
            context: $scheduledMessage->context,
            payload: [],
            meta: [
                'previous_scheduled_message_id' => $scheduledMessage->id,
                'previous_scheduled_message_status' => $scheduledMessage->status,
            ],
        );
    }

    private function hasNonTerminalSiblingVariantMessages(
        ScheduledMessage $scheduledMessage,
        int $campaignEnrollmentId,
    ): bool {
        if (! (bool) ($scheduledMessage->meta['campaign_step_waits_for_all_scheduled_variants'] ?? false)) {
            return false;
        }

        $campaignStepId = $scheduledMessage->meta['campaign_step_id'] ?? null;

        if (! is_numeric($campaignStepId)) {
            return false;
        }

        return ScheduledMessage::query()
            ->whereKeyNot($scheduledMessage->getKey())
            ->whereIn('status', [
                ScheduledMessage::STATUS_PENDING,
                ScheduledMessage::STATUS_SENDING,
            ])
            ->where('meta->campaign_enrollment_id', $campaignEnrollmentId)
            ->where('meta->campaign_step_id', (int) $campaignStepId)
            ->exists();
    }

    private function recordTerminalFailurePolicy(
        CampaignEnrollment $enrollment,
        ScheduledMessage $scheduledMessage,
        int $campaignEnrollmentId,
        mixed $campaignStepId,
        bool $hasNonTerminalSiblings,
        ScheduledMessageTerminalResult $currentTerminalResult,
    ): void {
        if (! is_numeric($campaignStepId)) {
            return;
        }

        $failedMessages = ScheduledMessage::query()
            ->where('status', ScheduledMessage::STATUS_FAILED)
            ->where('meta->campaign_enrollment_id', $campaignEnrollmentId)
            ->where('meta->campaign_step_id', (int) $campaignStepId)
            ->with('latestDeliveryAttempt')
            ->orderBy('id')
            ->get();

        if ($failedMessages->isEmpty()) {
            return;
        }

        $meta = is_array($enrollment->meta) ? $enrollment->meta : [];
        $failures = is_array($meta['scheduled_message_terminal_failures'] ?? null)
            ? $meta['scheduled_message_terminal_failures']
            : [];

        foreach ($failedMessages as $failedMessage) {
            $failureKey = (string) $failedMessage->id;
            $existingFailure = is_array($failures[$failureKey] ?? null)
                ? $failures[$failureKey]
                : [];
            $terminalResult = $failedMessage->is($scheduledMessage)
                && $currentTerminalResult->isFailed()
                    ? $currentTerminalResult
                    : ScheduledMessageTerminalResult::fromScheduledMessage(
                        $failedMessage,
                    );

            $failure = [
                'scheduled_message_id' => $failedMessage->id,
                'campaign_step_id' => (int) $campaignStepId,
                'campaign_step_variant_id' => data_get($failedMessage->meta, 'campaign_step_variant_id'),
                'campaign_step_variant_key' => data_get($failedMessage->meta, 'campaign_step_variant_key'),
                'failure_reason_code' => $existingFailure['failure_reason_code']
                    ?? $terminalResult->reasonCode,
                'failure_reason' => $existingFailure['failure_reason']
                    ?? $terminalResult->reason,
                'failed_at' => $existingFailure['failed_at']
                    ?? $terminalResult->occurredAt->toISOString(),
                'policy' => self::TERMINAL_FAILURE_POLICY,
                'decision' => $hasNonTerminalSiblings
                    ? 'wait_for_scheduled_sibling_variants'
                    : 'continue_to_next_campaign_step',
                'reconciled_by_scheduled_message_id' => $scheduledMessage->id,
                'reconciled_at' => now()->toISOString(),
            ];

            $failures[$failureKey] = $failure;
            $meta['last_scheduled_message_terminal_failure'] = $failure;
        }

        $meta['scheduled_message_terminal_failures'] = $failures;

        $enrollment->forceFill([
            'meta' => $meta,
        ])->save();
    }
}
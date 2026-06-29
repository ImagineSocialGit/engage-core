<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

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

            $scheduledMessage = $this->scheduleMessageStep(
                enrollment: $enrollment,
                campaign: $campaign,
                step: $step,
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
            $query->where('dispatch_key', $dispatchKey);
        }

        return $query->first();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     */
    private function scheduleMessageStep(
        CampaignEnrollment $enrollment,
        Campaign $campaign,
        CampaignStep $step,
        ?Model $context,
        array $payload,
        ?array $meta,
    ): ?ScheduledMessage {
        $definition = $this->messageDefinition(
            campaign: $campaign,
            step: $step,
        );

        $scheduledMessages = $this->dispatchMessageAction->handle(
            recipient: $enrollment->contact,
            channel: $definition['channel'],
            purpose: $definition['purpose'],
            scope: $definition['scope'],
            dispatchKeys: $step->dispatch_key,
            payload: $payload,
            context: $context,
            meta: array_replace_recursive([
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_id' => $campaign->id,
                'campaign_key' => $campaign->key,
                'campaign_step_id' => $step->id,
                'campaign_step' => $step->step_number,
            ], $step->meta ?? [], $meta ?? []),
            criteria: [
                'campaign_key' => $campaign->key,
                'step' => $step->step_number,
            ],
            definitions: [$definition],
        );

        return $scheduledMessages[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageDefinition(Campaign $campaign, CampaignStep $step): array
    {
        $message = $this->messageEnvelope($step);
        $schedule = $this->messageSchedule($step);
        $conditions = $this->conditions($step);

        return [
            'channel' => $this->stringOrDefault($message['channel'] ?? null, $campaign->channel),
            'purpose' => $this->stringOrDefault($message['purpose'] ?? null, $campaign->purpose),
            'scope' => $this->stringOrDefault($message['scope'] ?? null, $campaign->scope),
            'message_type' => $this->requiredString(
                $message['message_type'] ?? $message['type'] ?? null,
                'campaign step message.message_type',
            ),
            'payload_class' => $this->requiredString(
                $message['payload_class'] ?? null,
                'campaign step message.payload_class',
            ),
            'queue' => $this->requiredString($message['queue'] ?? null, 'campaign step message.queue'),
            'dispatch_keys' => [$step->dispatch_key],
            'payload' => $step->payload ?? [],
            'timing' => $schedule['timing'],
            'schedule' => $schedule['schedule'],
            'conditions' => $conditions,
            'campaign_key' => $campaign->key,
            'step' => $step->step_number,
            'config_path' => null,
            'skip_when_join_clicked' => (bool) data_get($step->meta ?? [], 'skip_when_join_clicked', false),
            'notification_type' => data_get($step->meta ?? [], 'notification_type'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function messageEnvelope(CampaignStep $step): array
    {
        $message = data_get($step->meta ?? [], 'message');

        if (! is_array($message)) {
            throw new InvalidArgumentException('Campaign step ['.$step->id.'] is missing meta.message.');
        }

        return $message;
    }

    /**
     * @return array{timing: string, schedule: array<string, mixed>|null}
     */
    private function messageSchedule(CampaignStep $step): array
    {
        $timing = data_get($step->criteria ?? [], 'timing');

        if (is_array($timing)) {
            return $this->normalizeTiming($timing);
        }

        $schedule = data_get($step->criteria ?? [], 'schedule');

        if (is_array($schedule)) {
            return $this->normalizeTiming($schedule);
        }

        return [
            'timing' => 'immediate',
            'schedule' => null,
        ];
    }

    /**
     * @param array<string, mixed> $timing
     * @return array{timing: string, schedule: array<string, mixed>|null}
     */
    private function normalizeTiming(array $timing): array
    {
        $type = $timing['type'] ?? 'immediate';

        if ($type === 'immediate') {
            return [
                'timing' => 'immediate',
                'schedule' => null,
            ];
        }

        if (! in_array($type, ['delay', 'anchored'], true)) {
            throw new InvalidArgumentException('Campaign step timing.type must be immediate, delay, or anchored.');
        }

        return [
            'timing' => 'scheduled',
            'schedule' => [
                'type' => $type,
                'minutes' => $this->timingMinutes($timing),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $timing
     */
    private function timingMinutes(array $timing): int
    {
        if (array_key_exists('minutes', $timing)) {
            return (int) $timing['minutes'];
        }

        if (array_key_exists('hours', $timing)) {
            return (int) $timing['hours'] * 60;
        }

        if (array_key_exists('days', $timing)) {
            return (int) $timing['days'] * 1440;
        }

        throw new InvalidArgumentException('Campaign step scheduled timing must include minutes, hours, or days.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conditions(CampaignStep $step): array
    {
        $conditions = data_get($step->criteria ?? [], 'conditions', []);

        return is_array($conditions) ? $conditions : [];
    }

    private function stepType(CampaignStep $step): string
    {
        $type = data_get($step->meta ?? [], 'type', 'message');

        return is_string($type) && trim($type) !== ''
            ? str_replace('-', '_', strtolower(trim($type)))
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

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('Missing required '.$field.'.');
        }

        return trim($value);
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $default;
    }
}
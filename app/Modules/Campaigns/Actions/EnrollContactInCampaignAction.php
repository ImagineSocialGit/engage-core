<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class EnrollContactInCampaignAction
{
    public function __construct(
        private readonly ScheduleCampaignStepMessagesAction $scheduleCampaignStepMessagesAction,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed>|null $startContext
     * @param array<string, mixed>|null $exitConditions
     */
    public function handle(
        Contact $contact,
        string $campaignKey,
        ?Model $source = null,
        array $payload = [],
        ?array $meta = null,
        ?array $startContext = null,
        ?array $exitConditions = null,
        ?string $channel = null,
        ?string $purpose = null,
        ?string $scope = null,
        ?string $dispatchKey = null,
    ): CampaignEnrollment {
        $campaign = $this->resolveCampaign(
            campaignKey: $campaignKey,
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
        );

        $enrollment = $this->existingEnrollment(
            contact: $contact,
            campaign: $campaign,
            campaignKey: $campaignKey,
        );

        if ($enrollment instanceof CampaignEnrollment) {
            return $enrollment;
        }

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'campaign_key' => $campaign->key,
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 0,
            'start_context' => $this->startContextWithPayload($startContext, $payload),
            'exit_conditions' => $exitConditions,
            'started_at' => Carbon::now(),
            'meta' => $meta,
            ...$this->flowRouteProvenance($meta ?? []),
        ]);

        $scheduledMessage = $this->scheduleNextSchedulableStep(
            enrollment: $enrollment,
            campaign: $campaign,
            contact: $contact,
            source: $source,
            payload: $payload,
            meta: $meta,
            dispatchKey: $dispatchKey,
        );

        if (! $scheduledMessage instanceof ScheduledMessage) {
            $this->completeEnrollment(
                enrollment: $enrollment,
                reason: CampaignEnrollment::EXIT_REASON_NO_NEXT_STEP,
            );
        }

        return $enrollment->refresh();
    }

    private function resolveCampaign(
        string $campaignKey,
        ?string $channel = null,
        ?string $purpose = null,
        ?string $scope = null,
    ): Campaign {
        $query = Campaign::query()
            ->active()
            ->where('key', $campaignKey);

        if ($channel !== null) {
            $query->where('channel', $this->normalizeSegment($channel));
        }

        if ($purpose !== null) {
            $query->where('purpose', $this->normalizeSegment($purpose));
        }

        if ($scope !== null) {
            $query->where('scope', $this->normalizeSegment($scope));
        }

        $campaign = $query->first();

        if (! $campaign instanceof Campaign) {
            throw new InvalidArgumentException('Active campaign not found for key ['.$campaignKey.'].');
        }

        return $campaign;
    }

    private function existingEnrollment(
        Contact $contact,
        Campaign $campaign,
        string $campaignKey,
    ): ?CampaignEnrollment {
        return CampaignEnrollment::query()
            ->where('contact_id', $contact->id)
            ->where(function ($query) use ($campaign, $campaignKey) {
                $query->where('campaign_id', $campaign->id)
                    ->orWhere('campaign_key', $campaignKey);
            })
            ->whereIn('status', [
                CampaignEnrollment::STATUS_ACTIVE,
                CampaignEnrollment::STATUS_PAUSED,
            ])
            ->first();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     */
    private function scheduleNextSchedulableStep(
        CampaignEnrollment $enrollment,
        Campaign $campaign,
        Contact $contact,
        ?Model $source,
        array $payload,
        ?array $meta,
        ?string $dispatchKey,
    ): ?ScheduledMessage {
        while ($enrollment->isActive()) {
            $step = $this->nextStep(
                campaign: $campaign,
                currentStep: (int) $enrollment->current_step,
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
                contact: $contact,
                context: $source,
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
        int $currentStep,
        ?string $dispatchKey = null,
    ): ?CampaignStep {
        $query = $campaign->activeSteps()
            ->where('step_number', '>', $currentStep)
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

    /**
     * @param array<string, mixed>|null $startContext
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function startContextWithPayload(?array $startContext, array $payload): ?array
    {
        if ($payload === []) {
            return $startContext;
        }

        $startContext ??= [];
        $existingPayload = is_array($startContext['payload'] ?? null)
            ? $startContext['payload']
            : [];

        $startContext['payload'] = array_replace_recursive(
            $existingPayload,
            $payload,
        );

        return $startContext;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, int|null>
     */
    private function flowRouteProvenance(array $meta): array
    {
        $flowRoute = is_array($meta['flow_route'] ?? null) ? $meta['flow_route'] : [];

        return [
            'flow_route_progress_id' => $this->nullableInt($flowRoute['flow_route_progress_id'] ?? null),
            'flow_route_plan_id' => $this->nullableInt($flowRoute['flow_route_plan_id'] ?? null),
            'flow_route_plan_item_id' => $this->nullableInt($flowRoute['flow_route_plan_item_id'] ?? null),
            'flow_route_progress_item_id' => $this->nullableInt($flowRoute['flow_route_progress_item_id'] ?? null),
            'flow_route_id' => $this->nullableInt($flowRoute['flow_route_id'] ?? null),
            'flow_route_point_id' => $this->nullableInt($flowRoute['flow_route_point_id'] ?? null),
            'flow_route_capability_id' => $this->nullableInt($flowRoute['flow_route_capability_id'] ?? null),
        ];
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

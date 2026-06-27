<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class EnrollContactInCampaignAction
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
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

        $firstStep = $this->firstStep($campaign, $dispatchKey);

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'campaign_key' => $campaign->key,
            'channel' => $campaign->channel,
            'purpose' => $campaign->purpose,
            'scope' => $campaign->scope,
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 0,
            'start_context' => $startContext,
            'exit_conditions' => $exitConditions,
            'started_at' => Carbon::now(),
            'meta' => $meta,
        ]);

        if (! $firstStep instanceof CampaignStep) {
            $this->completeEnrollment(
                enrollment: $enrollment,
                reason: CampaignEnrollment::EXIT_REASON_NO_NEXT_STEP,
            );

            return $enrollment;
        }

        $scheduledMessage = $this->scheduleStep(
            enrollment: $enrollment,
            campaign: $campaign,
            step: $firstStep,
            contact: $contact,
            source: $source,
            payload: $payload,
            meta: $meta,
        );

        if (! $scheduledMessage instanceof ScheduledMessage) {
            $this->completeEnrollment(
                enrollment: $enrollment,
                reason: CampaignEnrollment::EXIT_REASON_NO_NEXT_STEP,
            );

            return $enrollment;
        }

        $enrollment->forceFill([
            'current_step' => $firstStep->step_number,
            'current_campaign_step_id' => $firstStep->id,
            'last_scheduled_message_id' => $scheduledMessage->id,
        ])->save();

        return $enrollment;
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
            $query->where('channel', $channel);
        }

        if ($purpose !== null) {
            $query->where('purpose', $purpose);
        }

        if ($scope !== null) {
            $query->where('scope', $scope);
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

    private function firstStep(Campaign $campaign, ?string $dispatchKey = null): ?CampaignStep
    {
        $query = $campaign->activeSteps()
            ->where('step_number', 1);

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
        Contact $contact,
        ?Model $source,
        array $payload,
        ?array $meta,
    ): ?ScheduledMessage {
        $scheduledMessages = $this->dispatchMessageAction->handle(
            recipient: $contact,
            channel: $campaign->channel,
            purpose: $campaign->purpose,
            scope: $campaign->scope,
            dispatchKeys: $step->dispatch_key,
            payload: array_replace_recursive($step->payload ?? [], $payload),
            context: $source,
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
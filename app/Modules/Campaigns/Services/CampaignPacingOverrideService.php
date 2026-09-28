<?php

namespace App\Modules\Campaigns\Services;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationAssignment;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Messaging\Actions\ControlScheduledMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CampaignPacingOverrideService
{
    private const MAX_TARGET = 500;
    private const MAX_SCAN = 10000;

    public function __construct(
        private readonly CampaignSendPatternService $patterns,
        private readonly ControlScheduledMessageAction $control,
    ) {}

    /** @return array{available: bool, reason: string|null, candidate_count: int, window_end: Carbon|null, timezone: string, max_target: int} */
    public function preview(Campaign $campaign, ?Carbon $at = null): array
    {
        $at ??= now()->utc();
        $pattern = $this->patterns->forCampaign($campaign);
        $result = [
            'available' => false,
            'reason' => null,
            'candidate_count' => 0,
            'window_end' => null,
            'timezone' => $pattern['timezone'],
            'max_target' => self::MAX_TARGET,
        ];

        if (! $campaign->isActive() || $pattern['mode'] !== CampaignSendPatternService::MODE_SPREAD) {
            $result['reason'] = 'An active Campaign using spread email pacing is required.';

            return $result;
        }

        $local = $at->copy()->timezone($pattern['timezone']);
        $windowEnd = $local->copy()->setTimeFromTimeString($pattern['window_end']);
        $windowStart = $local->copy()->setTimeFromTimeString($pattern['window_start']);

        if (! in_array($local->dayOfWeekIso, $pattern['days_of_week'], true)
            || $local->lt($windowStart)
            || $local->gte($windowEnd->copy()->subMinute())
        ) {
            $result['reason'] = 'Use the override during an allowed sending day and window, with at least one minute left.';

            return $result;
        }

        $result['window_end'] = $windowEnd;
        $result['candidate_count'] = count($this->eligibleIds($campaign, $at, self::MAX_TARGET));
        $result['available'] = $result['candidate_count'] > 0;

        if (! $result['available']) {
            $result['reason'] = 'No ready, pending Campaign marketing emails can be moved into today’s remaining window.';
        }

        return $result;
    }

    /** @return array{rescheduled: int, window_end: Carbon} */
    public function apply(
        Campaign $campaign,
        User $actor,
        int $target,
        string $requestKey,
    ): array {
        if (! preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i', $requestKey)) {
            throw ValidationException::withMessages(['request_key' => 'A valid override request is required.']);
        }

        if ($target < 1 || $target > self::MAX_TARGET) {
            throw ValidationException::withMessages(['target' => 'Choose between 1 and 500 pending emails.']);
        }

        return DB::transaction(function () use ($campaign, $actor, $target, $requestKey): array {
            $campaign = Campaign::query()->whereKey($campaign->getKey())->lockForUpdate()->firstOrFail();
            $last = data_get($campaign->meta, 'pacing_override.last');

            if (is_array($last) && ($last['request_key'] ?? null) === $requestKey) {
                return [
                    'rescheduled' => (int) ($last['rescheduled'] ?? 0),
                    'window_end' => Carbon::parse($last['window_end']),
                ];
            }

            $now = now()->utc();
            $preview = $this->preview($campaign, $now);

            if (! $preview['available'] || ! $preview['window_end'] instanceof Carbon) {
                throw ValidationException::withMessages([
                    'target' => $preview['reason'] ?? 'No Campaign emails are ready for this override.',
                ]);
            }

            $ids = $this->eligibleIds($campaign, $now, $target);
            $count = count($ids);
            $lastSlot = $preview['window_end']->copy()->subMinute()->utc();
            $seconds = (int) $now->diffInSeconds($lastSlot);

            if ($count > $seconds) {
                throw ValidationException::withMessages([
                    'target' => 'There is not enough time left to space these messages at least one second apart.',
                ]);
            }

            foreach ($ids as $index => $id) {
                $message = ScheduledMessage::query()->whereKey($id)->lockForUpdate()->first();

                if (! $message instanceof ScheduledMessage
                    || $message->status !== ScheduledMessage::STATUS_PENDING
                    || $message->operational_state !== ScheduledMessage::OPERATIONAL_ACTIVE
                    || $message->manual_schedule_override_at !== null
                ) {
                    throw ValidationException::withMessages([
                        'target' => 'One of the selected messages changed. Preview and try again.',
                    ]);
                }

                $sendAt = $now->copy()->addSeconds((int) floor($seconds * $index / $count));
                $this->control->reschedule(
                    message: $message,
                    actor: $actor,
                    sendAt: $sendAt,
                    reason: 'Campaign remaining-window pacing override '.$requestKey,
                );
            }

            $meta = is_array($campaign->meta) ? $campaign->meta : [];
            $meta['pacing_override']['last'] = [
                'request_key' => $requestKey,
                'actor_id' => (int) $actor->getKey(),
                'target' => $target,
                'rescheduled' => $count,
                'window_end' => $preview['window_end']->toISOString(),
                'applied_at' => $now->toISOString(),
            ];
            $campaign->forceFill(['meta' => $meta])->save();

            return ['rescheduled' => $count, 'window_end' => $preview['window_end']];
        }, 3);
    }

    /** @return array<int, int> */
    private function eligibleIds(Campaign $campaign, Carbon $at, int $limit): array
    {
        $ids = $this->pendingQuery($campaign, $at)
            ->orderBy('send_at')
            ->orderBy('id')
            ->limit(self::MAX_SCAN)
            ->pluck('id');
        $selected = [];

        foreach ($ids->chunk(100) as $chunk) {
            $messages = ScheduledMessage::query()
                ->with(['messageChainStepVariant.messageChainStep', 'context'])
                ->whereIn('id', $chunk->all())
                ->get()
                ->keyBy('id');

            foreach ($chunk as $id) {
                $message = $messages->get($id);

                if ($message instanceof ScheduledMessage && $this->originallyDue($message, $at)) {
                    $selected[] = (int) $id;

                    if (count($selected) >= $limit) {
                        return $selected;
                    }
                }
            }
        }

        return $selected;
    }

    /** @return Builder<ScheduledMessage> */
    private function pendingQuery(Campaign $campaign, Carbon $at): Builder
    {
        $sequence = new CampaignEnrollment();
        $allocation = new CampaignAllocationAssignment();

        return ScheduledMessage::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $branch) => $branch
                    ->where('context_type', $sequence->getMorphClass())
                    ->whereIn('context_id', CampaignEnrollment::query()
                        ->select('id')->where('campaign_id', $campaign->getKey())))
                ->orWhere(fn (Builder $branch) => $branch
                    ->where('context_type', $allocation->getMorphClass())
                    ->whereIn('context_id', CampaignAllocationAssignment::query()
                        ->select('id')->where('campaign_id', $campaign->getKey()))))
            ->where('channel', MessageChannel::Email->value)
            ->where('purpose', MessagePurpose::Marketing->value)
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->where('operational_state', ScheduledMessage::OPERATIONAL_ACTIVE)
            ->whereNull('manual_schedule_override_at')
            ->where('send_at', '>', $at);
    }

    private function originallyDue(ScheduledMessage $message, Carbon $at): bool
    {
        $requested = data_get($message->meta, 'planning_requested_at');

        if (is_string($requested) && $requested !== '') {
            return Carbon::parse($requested)->lte($at);
        }

        $context = $message->context;

        if ($context instanceof CampaignAllocationAssignment) {
            $run = $context->run;
            $version = $context->messageChainVersion;
            $step = $version?->steps?->firstWhere('key', $context->message_step_key);
            $base = $run?->started_at ?? $run?->scheduled_for;

            if ($base !== null && $step instanceof MessageChainStep) {
                $due = Carbon::parse($base)->utc();

                if ($step->timing_type === MessageChainStep::TIMING_DELAY) {
                    $due->addSeconds(max(0, (int) $step->offset_seconds));
                } elseif ($step->timing_type !== MessageChainStep::TIMING_IMMEDIATE) {
                    return false;
                }

                return $due->lte($at);
            }

            return false;
        }

        // Old sequential messages have no persisted pre-pacing due time.
        // Only the immediate step can safely be promoted without that evidence.
        return $context instanceof CampaignEnrollment
            && $message->messageChainStepVariant?->messageChainStep?->timing_type
                === MessageChainStep::TIMING_IMMEDIATE
            && $message->created_at?->lte($at);
    }
}
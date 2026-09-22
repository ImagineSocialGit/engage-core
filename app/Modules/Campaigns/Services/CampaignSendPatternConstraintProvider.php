<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Messaging\Contracts\ScheduledMessageSendAtConstraintProvider;
use App\Modules\Messaging\Data\ScheduledMessagePlanningContext;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use RuntimeException;

final class CampaignSendPatternConstraintProvider implements ScheduledMessageSendAtConstraintProvider
{
    public function __construct(
        private readonly CampaignSendPatternService $patterns,
    ) {}

    public function constrain(
        ScheduledMessagePlanningContext $context,
        Carbon $sendAt,
    ): Carbon {
        if ($context->channel !== MessageChannel::Email->value
            || $context->purpose !== MessagePurpose::Marketing->value
            || ! $context->context instanceof CampaignEnrollment
        ) {
            return $sendAt;
        }

        $campaignEnrollment = $context->context;

        if ($campaignEnrollment->campaign_id === null) {
            return $sendAt;
        }

        $campaign = Campaign::query()
            ->whereKey($campaignEnrollment->campaign_id)
            ->lockForUpdate()
            ->first();

        if (! $campaign instanceof Campaign) {
            return $sendAt;
        }

        $pattern = $this->patterns->forCampaign($campaign);

        if ($pattern['mode'] !== CampaignSendPatternService::MODE_SPREAD) {
            return $sendAt;
        }

        return $this->allocate(
            campaign: $campaign,
            pattern: $pattern,
            requestedSendAt: $sendAt,
        );
    }

    /**
     * @param array{
     *     daily_limit: int,
     *     days_of_week: array<int, int>,
     *     window_start: string,
     *     window_end: string,
     *     timezone: string
     * } $pattern
     */
    private function allocate(
        Campaign $campaign,
        array $pattern,
        Carbon $requestedSendAt,
    ): Carbon {
        $timezone = $pattern['timezone'];
        $requestedLocal = $requestedSendAt->copy()->timezone($timezone);
        $cursor = $requestedLocal->copy();
        $dailyLimit = max(1, (int) $pattern['daily_limit']);

        for ($offset = 0; $offset <= 370; $offset++) {
            $day = $cursor->copy()->startOfDay()->addDays($offset);

            if (! in_array($day->dayOfWeekIso, $pattern['days_of_week'], true)) {
                continue;
            }

            [$startHour, $startMinute] = array_map(
                'intval',
                explode(':', $pattern['window_start']),
            );
            [$endHour, $endMinute] = array_map(
                'intval',
                explode(':', $pattern['window_end']),
            );

            $windowStart = $day->copy()->setTime($startHour, $startMinute);
            $windowEnd = $day->copy()->setTime($endHour, $endMinute);

            $dayStartUtc = $day->copy()->startOfDay()->utc();
            $dayEndUtc = $day->copy()->endOfDay()->utc();
            $query = $this->campaignMessagesForDay(
                campaign: $campaign,
                dayStartUtc: $dayStartUtc,
                dayEndUtc: $dayEndUtc,
            );
            $plannedCount = (clone $query)->count();

            if ($plannedCount >= $dailyLimit) {
                continue;
            }

            $lastSendAt = (clone $query)->max('send_at');
            $spacingSeconds = max(
                1,
                (int) floor(
                    $windowStart->diffInSeconds($windowEnd)
                    / $dailyLimit,
                ),
            );

            $candidate = $windowStart->copy();

            if ($day->isSameDay($requestedLocal)
                && $requestedLocal->gt($candidate)
            ) {
                $candidate = $requestedLocal->copy();
            }

            if ($lastSendAt !== null) {
                $afterLast = Carbon::parse($lastSendAt)
                    ->timezone($timezone)
                    ->addSeconds($spacingSeconds);

                if ($afterLast->gt($candidate)) {
                    $candidate = $afterLast;
                }
            }

            if ($candidate->lt($windowStart)) {
                $candidate = $windowStart->copy();
            }

            if ($candidate->gt($windowEnd)) {
                continue;
            }

            return $candidate->utc();
        }

        throw new RuntimeException(
            'Unable to allocate a Campaign send-pattern slot within the next 370 days.',
        );
    }

    private function campaignMessagesForDay(
        Campaign $campaign,
        Carbon $dayStartUtc,
        Carbon $dayEndUtc,
    ): Builder {
        $campaignEnrollment = new CampaignEnrollment();

        return ScheduledMessage::query()
            ->where(
                'context_type',
                $campaignEnrollment->getMorphClass(),
            )
            ->whereIn(
                'context_id',
                CampaignEnrollment::query()
                    ->select('id')
                    ->where('campaign_id', $campaign->getKey()),
            )
            ->where('channel', MessageChannel::Email->value)
            ->where('purpose', MessagePurpose::Marketing->value)
            ->whereBetween('send_at', [$dayStartUtc, $dayEndUtc])
            ->whereNotIn('status', [
                ScheduledMessage::STATUS_SKIPPED,
                ScheduledMessage::STATUS_CANCELLED,
            ]);
    }
}
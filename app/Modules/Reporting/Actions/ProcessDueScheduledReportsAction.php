<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Models\ScheduledReportSubscription;
use App\Modules\Reporting\Services\ScheduledReportDeliveryRegistry;
use App\Modules\Reporting\Services\ScheduledReportRegistry;
use App\Modules\Reporting\Services\ScheduledReportScheduleCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ProcessDueScheduledReportsAction
{
    public function __construct(
        private readonly ScheduledReportRegistry $reports,
        private readonly ScheduledReportDeliveryRegistry $delivery,
        private readonly ScheduledReportScheduleCalculator $schedule,
    ) {}

    public function handle(): int
    {
        $ids = ScheduledReportSubscription::query()
            ->where('is_enabled', true)
            ->whereNotNull('next_send_at')
            ->where('next_send_at', '<=', now())
            ->orderBy('next_send_at')
            ->orderBy('id')
            ->limit(50)
            ->pluck('id');

        $delivered = 0;

        foreach ($ids as $id) {
            $delivered += $this->process((int) $id);
        }

        return $delivered;
    }

    private function process(int $id): int
    {
        return DB::transaction(function () use ($id): int {
            $subscription = ScheduledReportSubscription::query()
                ->with('recipients')
                ->lockForUpdate()
                ->find($id);

            if (! $subscription instanceof ScheduledReportSubscription
                || ! $subscription->is_enabled
                || ! $subscription->next_send_at
                || $subscription->next_send_at->isFuture()
            ) {
                return 0;
            }

            $provider = $this->reports->find($subscription->report_key);

            if ($provider === null) {
                $subscription->forceFill([
                    'is_enabled' => false,
                    'next_send_at' => null,
                ])->save();

                return 0;
            }

            $scheduledFor = CarbonImmutable::instance(
                $subscription->next_send_at,
            );
            $result = $provider->build(
                parameters: is_array($subscription->parameters)
                    ? $subscription->parameters
                    : [],
                generatedAt: now(),
                timezone: $subscription->timezone,
            );
            $occurrenceKey = 'scheduled:'.$scheduledFor
                ->utc()
                ->format('YmdHis');
            $count = 0;

            foreach ($subscription->recipients as $recipient) {
                if ($this->delivery->deliver(
                    subscription: $subscription,
                    recipient: $recipient,
                    result: $result,
                    occurrenceKey: $occurrenceKey,
                )) {
                    $count++;
                }
            }

            $subscription->forceFill([
                'last_sent_at' => $count > 0 ? now() : $subscription->last_sent_at,
                'next_send_at' => $this->schedule->next(
                    daysOfWeek: $subscription->days_of_week ?? [],
                    sendTime: $subscription->send_time,
                    timezone: $subscription->timezone,
                    after: now(),
                ),
            ])->save();

            return $count;
        }, 3);
    }
}
<?php

namespace App\Modules\Reporting\Actions;

use App\Modules\Reporting\Models\ScheduledReportSubscription;
use App\Modules\Reporting\Services\ScheduledReportDeliveryRegistry;
use App\Modules\Reporting\Services\ScheduledReportRegistry;
use Illuminate\Support\Str;
use RuntimeException;

final class SendScheduledReportNowAction
{
    public function __construct(
        private readonly ScheduledReportRegistry $reports,
        private readonly ScheduledReportDeliveryRegistry $delivery,
    ) {}

    public function handle(ScheduledReportSubscription $subscription): int
    {
        $subscription->loadMissing('recipients');

        $provider = $this->reports->find($subscription->report_key)
            ?? throw new RuntimeException(
                "Scheduled report [{$subscription->report_key}] is not available.",
            );

        $result = $provider->build(
            parameters: is_array($subscription->parameters)
                ? $subscription->parameters
                : [],
            generatedAt: now(),
            timezone: $subscription->timezone,
        );
        $occurrenceKey = 'manual:'.Str::uuid();
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

        return $count;
    }
}
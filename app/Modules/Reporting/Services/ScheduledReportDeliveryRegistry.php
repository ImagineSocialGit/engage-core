<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Contracts\ScheduledReportDeliveryDriver;
use App\Modules\Reporting\Data\ScheduledReportResult;
use App\Modules\Reporting\Models\ScheduledReportSubscription;
use App\Modules\Reporting\Models\ScheduledReportSubscriptionRecipient;
use RuntimeException;

final class ScheduledReportDeliveryRegistry
{
    public function __construct(
        private readonly iterable $drivers,
    ) {}

    public function deliver(
        ScheduledReportSubscription $subscription,
        ScheduledReportSubscriptionRecipient $recipient,
        ScheduledReportResult $result,
        string $occurrenceKey,
    ): bool {
        foreach ($this->drivers as $driver) {
            if (! $driver instanceof ScheduledReportDeliveryDriver) {
                throw new RuntimeException(
                    'Scheduled report delivery contributors must implement '
                    .ScheduledReportDeliveryDriver::class.'.',
                );
            }

            if (! $driver->supports(
                $recipient->recipient_type,
                $subscription->channel,
            )) {
                continue;
            }

            return $driver->deliver(
                subscription: $subscription,
                recipient: $recipient,
                result: $result,
                occurrenceKey: $occurrenceKey,
            );
        }

        return false;
    }
}
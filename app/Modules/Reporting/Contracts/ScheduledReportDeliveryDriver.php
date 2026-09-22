<?php

namespace App\Modules\Reporting\Contracts;

use App\Modules\Reporting\Data\ScheduledReportResult;
use App\Modules\Reporting\Models\ScheduledReportSubscription;
use App\Modules\Reporting\Models\ScheduledReportSubscriptionRecipient;

interface ScheduledReportDeliveryDriver
{
    public const TAG = 'reporting.scheduled_report_delivery_drivers';

    public function supports(string $recipientType, string $channel): bool;

    public function deliver(
        ScheduledReportSubscription $subscription,
        ScheduledReportSubscriptionRecipient $recipient,
        ScheduledReportResult $result,
        string $occurrenceKey,
    ): bool;
}
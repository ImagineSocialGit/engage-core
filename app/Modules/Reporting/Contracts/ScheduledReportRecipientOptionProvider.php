<?php

namespace App\Modules\Reporting\Contracts;

use App\Modules\Reporting\Data\ScheduledReportRecipientOption;

interface ScheduledReportRecipientOptionProvider
{
    public const TAG = 'reporting.scheduled_report_recipient_option_providers';

    /** @return iterable<ScheduledReportRecipientOption> */
    public function options(): iterable;
}
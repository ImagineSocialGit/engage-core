<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class WebinarWaitlistNotificationStartResolver
{
    public function resolve(
        WebinarWaitlistSignup $signup,
        Webinar $webinar,
        ?CarbonInterface $now = null,
    ): CarbonImmutable {
        $now = $now
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');

        if (! $signup->isRecurringNotificationSubscription()) {
            return $now;
        }

        $leadDays = max(0, (int) config(
            'webinars.post_event.future_availability_subscription.notification_lead_days',
            0,
        ));

        if ($leadDays === 0 || ! $webinar->starts_at instanceof CarbonInterface) {
            return $now;
        }

        $target = CarbonImmutable::instance($webinar->starts_at)
            ->utc()
            ->subDays($leadDays);

        return $target->isAfter($now)
            ? $target
            : $now;
    }
}
<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Modules\Webinars\Services\WebinarWaitlistNotificationStartResolver;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class WebinarWaitlistNotificationStartResolverTest extends TestCase
{
    public function test_recurring_notification_starts_at_configured_lead_time(): void
    {
        config()->set(
            'webinars.post_event.future_availability_subscription.notification_lead_days',
            14,
        );

        $now = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
        $webinar = (new Webinar())->forceFill([
            'starts_at' => $now->addDays(30),
        ]);
        $signup = (new WebinarWaitlistSignup())->forceFill([
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
        ]);

        $resolved = app(WebinarWaitlistNotificationStartResolver::class)
            ->resolve($signup, $webinar, $now);

        $this->assertTrue($resolved->equalTo($now->addDays(16)));
    }

    public function test_recurring_notification_inside_lead_window_starts_now(): void
    {
        config()->set(
            'webinars.post_event.future_availability_subscription.notification_lead_days',
            14,
        );

        $now = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
        $webinar = (new Webinar())->forceFill([
            'starts_at' => $now->addDays(7),
        ]);
        $signup = (new WebinarWaitlistSignup())->forceFill([
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
        ]);

        $resolved = app(WebinarWaitlistNotificationStartResolver::class)
            ->resolve($signup, $webinar, $now);

        $this->assertTrue($resolved->equalTo($now));
    }

    public function test_one_shot_waitlist_notification_remains_immediate(): void
    {
        config()->set(
            'webinars.post_event.future_availability_subscription.notification_lead_days',
            14,
        );

        $now = CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC');
        $webinar = (new Webinar())->forceFill([
            'starts_at' => $now->addDays(30),
        ]);
        $signup = (new WebinarWaitlistSignup())->forceFill([
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_ONCE,
        ]);

        $resolved = app(WebinarWaitlistNotificationStartResolver::class)
            ->resolve($signup, $webinar, $now);

        $this->assertTrue($resolved->equalTo($now));
    }
}
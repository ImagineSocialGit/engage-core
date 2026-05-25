<?php

namespace Tests\Feature\Webinars;

use App\Actions\Webinars\NotifyWebinarWaitlistAction;
use App\Jobs\Messaging\SendEmailMessageJob;
use App\Jobs\Messaging\SendSmsMessageJob;
use App\Models\Webinar;
use App\Models\WebinarSeries;
use App\Models\WebinarWaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class NotifyWebinarWaitlistActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_waitlist_notifications(): void
    {
        Queue::fake();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
            'slug' => 'home-buyer-game-plan',
            'status' => 'active',
        ]);

        Webinar::query()->create([
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-1',
            'title' => 'Home Buyer Game Plan',
            'slug' => 'home-buyer-game-plan-1',
            'join_url' => 'https://example.com/join',
            'registration_url' => 'https://example.com/register',
            'starts_at' => now()->addDays(7),
            'ends_at' => now()->addDays(7)->addHour(),
            'timezone' => 'America/Chicago',
        ]);

        $signup = WebinarWaitlistSignup::query()->create([
            'webinar_series_id' => $series->id,
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@example.com',
            'phone' => '+15555555555',
            'email_consent_at' => now(),
            'sms_consent_at' => now(),
        ]);

        $count = app(NotifyWebinarWaitlistAction::class)
            ->handle($series);

        $this->assertSame(1, $count);

        Queue::assertPushed(SendEmailMessageJob::class, 1);
        Queue::assertPushed(SendSmsMessageJob::class, 1);

        $signup->refresh();

        $this->assertNotNull($signup->notified_at);
    }

    public function test_it_skips_already_notified_signups(): void
    {
        Queue::fake();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
            'slug' => 'home-buyer-game-plan',
            'status' => 'active',
        ]);

        Webinar::query()->create([
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-1',
            'title' => 'Home Buyer Game Plan',
            'slug' => 'home-buyer-game-plan-1',
            'join_url' => 'https://example.com/join',
            'registration_url' => 'https://example.com/register',
            'starts_at' => now()->addDays(7),
            'ends_at' => now()->addDays(7)->addHour(),
            'timezone' => 'America/Chicago',
        ]);

        WebinarWaitlistSignup::query()->create([
            'webinar_series_id' => $series->id,
            'first_name' => 'Jeff',
            'email' => 'jeff@example.com',
            'email_consent_at' => now(),
            'notified_at' => now(),
        ]);

        $count = app(NotifyWebinarWaitlistAction::class)
            ->handle($series);

        $this->assertSame(0, $count);

        Queue::assertNothingPushed();
    }
}
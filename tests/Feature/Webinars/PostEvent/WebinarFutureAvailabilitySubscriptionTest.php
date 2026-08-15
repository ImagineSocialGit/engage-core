<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Webinars\Actions\PostEvent\EnsureMissedWebinarFutureAvailabilitySubscriptionAction;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarAttendanceAction;
use App\Modules\Webinars\Jobs\NotifyWebinarWaitlistJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarFutureAvailabilitySubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));
        Queue::fake();

        Config::set('webinars.post_event.future_availability_subscription', [
            'enabled' => true,
            'duration_days' => 365,
            'channels' => [
                'email',
                'sms',
            ],
        ]);

        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'webinar_waitlists' => true,
            ],
            'purpose_scopes' => [
                'marketing:webinar_waitlist' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_waitlists' => true,
            ],
            'purpose_scopes' => [
                'marketing:webinar_waitlist' => true,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_authoritative_missed_outcome_creates_a_recurring_subscription(): void
    {
        [$series, $webinar, $registration, $contact] = $this->registrationCandidate();
        $this->grantMarketingConsent($contact, MessageChannel::Email);
        $this->grantMarketingConsent($contact, MessageChannel::Sms);

        $action = app(RecordWebinarAttendanceAction::class);

        $action->execute(
            webinar: $webinar,
            provider: 'zoom',
            attendanceRecords: collect(),
            finalizeMissed: false,
        );

        $this->assertSame('registered', $registration->fresh()->status);
        $this->assertSame(0, WebinarWaitlistSignup::query()->count());

        $action->execute(
            webinar: $webinar,
            provider: 'zoom',
            attendanceRecords: collect(),
            finalizeMissed: true,
        );

        $signup = WebinarWaitlistSignup::query()->sole();

        $this->assertSame('missed', $registration->fresh()->status);
        $this->assertSame($series->getKey(), $signup->webinar_series_id);
        $this->assertSame($contact->getKey(), $signup->contact_id);
        $this->assertSame(
            WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
            $signup->notification_mode,
        );
        $this->assertNull($signup->notified_at);
        $this->assertNull($signup->ended_at);
        $this->assertTrue($signup->expires_at->equalTo(now()->addDays(365)));
        $this->assertEquals(
            ['email', 'sms'],
            data_get($signup->meta, 'accepted_channels.marketing'),
        );
        $this->assertSame(
            'missed_webinar',
            data_get($signup->meta, 'future_availability_subscription.source'),
        );
        $this->assertSame(
            $registration->getKey(),
            data_get($signup->meta, 'future_availability_subscription.latest_webinar_registration_id'),
        );
        $this->assertSame(
            $webinar->getKey(),
            data_get($signup->meta, 'future_availability_subscription.latest_webinar_id'),
        );

        Queue::assertPushed(
            NotifyWebinarWaitlistJob::class,
            fn (NotifyWebinarWaitlistJob $job): bool =>
                $job->seriesId === $series->getKey()
                && $job->webinarId === null
                && $job->notificationMode === WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
        );
    }

    public function test_missing_marketing_consent_does_not_create_a_subscription(): void
    {
        [, $webinar] = $this->registrationCandidate();

        app(RecordWebinarAttendanceAction::class)->execute(
            webinar: $webinar,
            provider: 'zoom',
            attendanceRecords: collect(),
            finalizeMissed: true,
        );

        $this->assertSame(0, WebinarWaitlistSignup::query()->count());
        Queue::assertNotPushed(NotifyWebinarWaitlistJob::class);
    }

    public function test_existing_one_shot_signup_is_upgraded_without_duplicate_or_losing_first_notification(): void
    {
        [$series, , $registration, $contact] = $this->registrationCandidate([
            'status' => 'missed',
            'meta' => [
                'attendance' => [
                    'status' => 'missed',
                ],
            ],
        ]);
        $this->grantMarketingConsent($contact, MessageChannel::Email);

        $firstNotifiedAt = now()->subMonth();
        $existing = WebinarWaitlistSignup::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_series_id' => $series->getKey(),
            'notified_at' => $firstNotifiedAt,
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_ONCE,
            'expires_at' => null,
            'ended_at' => null,
            'source_page' => 'webinar-notify-me',
            'meta' => [
                'accepted_channels' => [
                    'marketing' => [
                        'email',
                    ],
                ],
            ],
        ]);

        $result = app(EnsureMissedWebinarFutureAvailabilitySubscriptionAction::class)
            ->execute($registration);

        $this->assertNotNull($result);
        $this->assertSame(1, WebinarWaitlistSignup::query()->count());

        $existing->refresh();

        $this->assertSame(
            WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
            $existing->notification_mode,
        );
        $this->assertTrue($existing->notified_at->equalTo($firstNotifiedAt));
        $this->assertTrue($existing->expires_at->equalTo(now()->addDays(365)));
        $this->assertSame('webinar-notify-me', $existing->source_page);
    }

    public function test_explicitly_ended_subscription_is_not_reactivated_by_another_missed_webinar(): void
    {
        [$series, , $registration, $contact] = $this->registrationCandidate([
            'status' => 'missed',
        ]);
        $this->grantMarketingConsent($contact, MessageChannel::Email);

        $endedAt = now()->subDay();
        $signup = WebinarWaitlistSignup::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_series_id' => $series->getKey(),
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
            'expires_at' => now()->addMonth(),
            'ended_at' => $endedAt,
        ]);

        $result = app(EnsureMissedWebinarFutureAvailabilitySubscriptionAction::class)
            ->execute($registration);

        $this->assertNull($result);
        $this->assertTrue($signup->fresh()->ended_at->equalTo($endedAt));
        Queue::assertNotPushed(NotifyWebinarWaitlistJob::class);
    }

    public function test_notification_eligibility_keeps_active_recurring_rows_after_first_notification(): void
    {
        $activeRecurring = WebinarWaitlistSignup::factory()->notified()->create([
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
            'expires_at' => now()->addMonth(),
            'ended_at' => null,
        ]);
        $pendingOneShot = WebinarWaitlistSignup::factory()->create([
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_ONCE,
            'expires_at' => null,
            'ended_at' => null,
        ]);
        WebinarWaitlistSignup::factory()->notified()->create([
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_ONCE,
        ]);
        WebinarWaitlistSignup::factory()->notified()->create([
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
            'expires_at' => now()->subMinute(),
        ]);
        WebinarWaitlistSignup::factory()->notified()->create([
            'notification_mode' => WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING,
            'expires_at' => now()->addMonth(),
            'ended_at' => now()->subMinute(),
        ]);

        $this->assertEqualsCanonicalizing(
            [
                $activeRecurring->getKey(),
                $pendingOneShot->getKey(),
            ],
            WebinarWaitlistSignup::query()
                ->eligibleForNotification()
                ->pluck('id')
                ->all(),
        );
        $this->assertEquals(
            [$activeRecurring->getKey()],
            WebinarWaitlistSignup::query()
                ->eligibleForNotification(WebinarWaitlistSignup::NOTIFICATION_MODE_RECURRING)
                ->pluck('id')
                ->all(),
        );
        $this->assertEquals(
            [$pendingOneShot->getKey()],
            WebinarWaitlistSignup::query()
                ->eligibleForNotification(WebinarWaitlistSignup::NOTIFICATION_MODE_ONCE)
                ->pluck('id')
                ->all(),
        );
    }

    /**
     * @param array<string, mixed> $registrationOverrides
     * @return array{WebinarSeries, Webinar, WebinarRegistration, Contact}
     */
    private function registrationCandidate(array $registrationOverrides = []): array
    {
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
        ]);
        $contact = Contact::factory()->create([
            'email' => 'missed@example.test',
            'phone' => '+15555550123',
        ]);
        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($contact)
            ->create(array_replace_recursive([
                'status' => 'registered',
                'attended_at' => null,
                'cancelled_at' => null,
                'meta' => [],
            ], $registrationOverrides));

        return [$series, $webinar, $registration, $contact];
    }

    private function grantMarketingConsent(
        Contact $contact,
        MessageChannel $channel,
    ): void {
        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => $channel->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);
    }
}
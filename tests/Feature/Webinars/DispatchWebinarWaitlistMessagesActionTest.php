<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Webinars\Actions\DispatchWebinarWaitlistMessagesAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebinarWaitlistMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        Config::set('cache-keys.enabled', false);

        Config::set('messaging.email.from.marketing', [
            'address' => 'marketing@engagecore.test',
            'name' => 'Engage Core Marketing',
        ]);

        Config::set('messaging.email.providers.resend.from.marketing', [
            'address' => 'marketing@engagecore.test',
            'name' => 'Engage Core Marketing',
        ]);

        $this->configureWebinarWaitlistChannelAvailability();
    }

    public function test_it_dispatches_email_waitlist_notification_when_sms_is_hidden(): void
    {
        Queue::fake();

        $this->configureWaitlistMessages();

        $series = $this->createSeries();
        $webinar = $this->createWebinar($series);
        $contact = $this->createContact([
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ]);

        $signup = $this->createSignup($series, $contact, [
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ]);

        app(DispatchWebinarWaitlistMessagesAction::class)->handle($webinar);

        $this->assertSame(1, ScheduledMessage::query()->count());

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'context_type' => $signup->getMorphClass(),
            'context_id' => $signup->id,
            'channel' => MessageChannel::Email->value,
            'message_type' => 'scheduled_notice',
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar_waitlist',
            'payload_class' => EmailPayload::class,
            'status' => 'pending',
        ]);

        $this->assertDatabaseMissing('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => MessageChannel::Sms->value,
        ]);

        $signup->refresh();

        $this->assertNotNull($signup->notified_at);

        Queue::assertPushed(SendScheduledMessageJob::class, 1);
    }

    public function test_it_dispatches_sms_waitlist_notification_when_sms_is_available_and_accepted(): void
    {
        Queue::fake();

        $this->enableWebinarWaitlistSms();
        $this->configureWaitlistMessages();

        $series = $this->createSeries();
        $webinar = $this->createWebinar($series);
        $contact = $this->createContact([
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ]);

        $signup = $this->createSignup($series, $contact, [
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ]);

        app(DispatchWebinarWaitlistMessagesAction::class)->handle($webinar);

        $this->assertSame(2, ScheduledMessage::query()->count());

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'context_type' => $signup->getMorphClass(),
            'context_id' => $signup->id,
            'channel' => MessageChannel::Email->value,
            'message_type' => 'scheduled_notice',
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar_waitlist',
            'payload_class' => EmailPayload::class,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'context_type' => $signup->getMorphClass(),
            'context_id' => $signup->id,
            'channel' => MessageChannel::Sms->value,
            'message_type' => 'scheduled_notice',
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar_waitlist',
            'payload_class' => SmsPayload::class,
            'status' => 'pending',
        ]);

        $signup->refresh();

        $this->assertNotNull($signup->notified_at);

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    public function test_it_does_not_dispatch_sms_when_sms_is_available_but_not_accepted_for_signup(): void
    {
        Queue::fake();

        $this->enableWebinarWaitlistSms();
        $this->configureWaitlistMessages();

        $series = $this->createSeries();
        $webinar = $this->createWebinar($series);
        $contact = $this->createContact([
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ]);

        $signup = $this->createSignup($series, $contact, [
            MessageChannel::Email->value,
        ]);

        app(DispatchWebinarWaitlistMessagesAction::class)->handle($webinar);

        $this->assertSame(1, ScheduledMessage::query()->count());

        $this->assertDatabaseHas('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
        ]);

        $this->assertDatabaseMissing('scheduled_messages', [
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => MessageChannel::Sms->value,
        ]);

        $signup->refresh();

        $this->assertNotNull($signup->notified_at);

        Queue::assertPushed(SendScheduledMessageJob::class, 1);
    }

    public function test_it_does_not_mark_signup_notified_when_no_accepted_channels_are_available(): void
    {
        Queue::fake();

        $this->configureWaitlistMessages();

        $series = $this->createSeries();
        $webinar = $this->createWebinar($series);
        $contact = $this->createContact([
            MessageChannel::Sms->value,
        ]);

        $signup = $this->createSignup($series, $contact, [
            MessageChannel::Sms->value,
        ]);

        app(DispatchWebinarWaitlistMessagesAction::class)->handle($webinar);

        $this->assertSame(0, ScheduledMessage::query()->count());

        $signup->refresh();

        $this->assertNull($signup->notified_at);

        Queue::assertNothingPushed();
    }

    public function test_it_skips_already_notified_signups(): void
    {
        Queue::fake();

        $this->configureWaitlistMessages();

        $series = $this->createSeries();
        $webinar = $this->createWebinar($series);
        $contact = $this->createContact([
            MessageChannel::Email->value,
        ]);

        $this->createSignup($series, $contact, [
            MessageChannel::Email->value,
        ], notified: true);

        app(DispatchWebinarWaitlistMessagesAction::class)->handle($webinar);

        Queue::assertNothingPushed();

        $this->assertSame(0, ScheduledMessage::query()->count());
    }

    private function configureWaitlistMessages(): void
    {
        Config::set('messaging.email.marketing.webinar_waitlist', [
            'scheduled_notice' => [
                'dispatch_key' => 'webinar_added',
                'timing' => 'immediate',
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'New webinar scheduled',
                    'body' => 'A new webinar is available.',
                ],
            ],
        ]);

        Config::set('messaging.sms.marketing.webinar_waitlist', [
            'scheduled_notice' => [
                'dispatch_key' => 'webinar_added',
                'timing' => 'immediate',
                'payload_class' => SmsPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'message' => 'A new webinar is available.',
                ],
            ],
        ]);
    }

    private function createSeries(): WebinarSeries
    {
        return WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
            'slug' => 'home-buyer-game-plan',
            'status' => 'active',
        ]);
    }

    private function createWebinar(WebinarSeries $series): Webinar
    {
        return Webinar::query()->create([
            'webinar_series_id' => $series->id,
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
    }

    /**
     * @param array<int, string> $consentedChannels
     */
    private function createContact(array $consentedChannels): Contact
    {
        $contact = Contact::query()->create([
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'name' => 'Jeff Yarnall',
            'email' => 'jeff@example.com',
            'phone' => '+15555555555',
            'status' => 'new',
            'source' => 'webinar_waitlist',
        ]);

        foreach ($consentedChannels as $channel) {
            MessageConsent::query()->create([
                'contact_id' => $contact->id,
                'channel' => $channel,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'webinar_waitlist',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        return $contact;
    }

    /**
     * @param array<int, string> $acceptedChannels
     */
    private function createSignup(
        WebinarSeries $series,
        Contact $contact,
        array $acceptedChannels,
        bool $notified = false,
    ): WebinarWaitlistSignup {
        return WebinarWaitlistSignup::query()->create([
            'contact_id' => $contact->id,
            'webinar_series_id' => $series->id,
            'notified_at' => $notified ? now() : null,
            'meta' => [
                'accepted_channels' => [
                    'marketing' => $acceptedChannels,
                ],
            ],
        ]);
    }

    private function configureWebinarWaitlistChannelAvailability(): void
    {
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
                'webinar_waitlists' => false,
            ],
            'purpose_scopes' => [
                'marketing:webinar_waitlist' => true,
            ],
        ]);
    }

    private function enableWebinarWaitlistSms(): void
    {
        Config::set('messaging.channel_availability.sms.surfaces.webinar_waitlists', true);
    }
}

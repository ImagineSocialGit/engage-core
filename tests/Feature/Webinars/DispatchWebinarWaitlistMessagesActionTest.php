<?php

namespace Tests\Feature\Webinars;

use App\Actions\Webinars\DispatchWebinarWaitlistMessagesAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Messaging\Payloads\EmailPayload;
use App\Messaging\Payloads\SmsPayload;
use App\Models\Contact;
use App\Models\ScheduledMessage;
use App\Models\Webinar;
use App\Models\WebinarSeries;
use App\Models\WebinarWaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebinarWaitlistMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_waitlist_notifications(): void
    {
        Queue::fake();

        $this->configureWaitlistMessages();

        $series = $this->createSeries();
        $webinar = $this->createWebinar($series);
        $contact = $this->createContact();

        $signup = WebinarWaitlistSignup::query()->create([
            'contact_id' => $contact->id,
            'webinar_series_id' => $series->id,
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@example.com',
            'phone' => '+15555555555',
        ]);

        app(DispatchWebinarWaitlistMessagesAction::class)->handle($webinar);

        $this->assertSame(2, ScheduledMessage::query()->count());

        $this->assertDatabaseHas('scheduled_messages', [
            'contact_id' => $contact->id,
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
            'contact_id' => $contact->id,
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

    public function test_it_skips_already_notified_signups(): void
    {
        Queue::fake();

        $this->configureWaitlistMessages();

        $series = $this->createSeries();
        $webinar = $this->createWebinar($series);
        $contact = $this->createContact();

        WebinarWaitlistSignup::query()->create([
            'contact_id' => $contact->id,
            'webinar_series_id' => $series->id,
            'first_name' => 'Jeff',
            'email' => 'jeff@example.com',
            'phone' => '+15555555555',
            'notified_at' => now(),
        ]);

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

    private function createContact(): Contact
    {
        return Contact::query()->create([
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'name' => 'Jeff Yarnall',
            'email' => 'jeff@example.com',
            'phone' => '+15555555555',
            'status' => 'new',
            'source' => 'webinar_waitlist',
        ]);
    }
}
<?php

namespace Tests\Feature\Webinars;

use App\Actions\Webinars\DispatchWebinarWaitlistMessagesAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Models\Contact;
use App\Models\ScheduledMessage;
use App\Models\Webinar;
use App\Models\WebinarSeries;
use App\Models\WebinarWaitlistSignup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebinarWaitlistMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_waitlist_notifications(): void
    {
        Queue::fake();

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

        $this->grantConsent($contact, MessageChannel::Email);
        $this->grantConsent($contact, MessageChannel::Sms);

        app(DispatchWebinarWaitlistMessagesAction::class)->handle($webinar);

        Queue::assertPushed(SendScheduledMessageJob::class, 2);

        $this->assertSame(2, ScheduledMessage::query()->count());

        $this->assertDatabaseHas('scheduled_messages', [
            'contact_id' => $contact->id,
            'context_type' => $signup->getMorphClass(),
            'context_id' => $signup->id,
            'channel' => MessageChannel::Email->value,
            'message_type' => 'webinar_waitlist_scheduled',
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar_waitlist',
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('scheduled_messages', [
            'contact_id' => $contact->id,
            'context_type' => $signup->getMorphClass(),
            'context_id' => $signup->id,
            'channel' => MessageChannel::Sms->value,
            'message_type' => 'webinar_waitlist_scheduled',
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar_waitlist',
            'status' => 'pending',
        ]);

        $signup->refresh();

        $this->assertNotNull($signup->notified_at);
    }

    public function test_it_skips_already_notified_signups(): void
    {
        Queue::fake();

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

        $this->grantConsent($contact, MessageChannel::Email);
        $this->grantConsent($contact, MessageChannel::Sms);

        app(DispatchWebinarWaitlistMessagesAction::class)->handle($webinar);

        Queue::assertNothingPushed();

        $this->assertSame(0, ScheduledMessage::query()->count());
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
            'email' => 'jeff@example.com',
            'phone' => '+15555555555',
            'status' => 'new',
            'source' => 'webinar_waitlist',
        ]);
    }

    private function grantConsent(Contact $contact, MessageChannel $channel): void
    {
        DB::table('message_consents')->insert([
            'contact_id' => $contact->id,
            'channel' => $channel->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar_waitlist',
            'consented_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'source' => 'webinar_waitlist',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
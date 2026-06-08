<?php

namespace Tests\Feature\Webinars;

use App\Actions\Webinars\DispatchWebinarRegistrationMessagesAction;
use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Models\Contact;
use App\Models\ScheduledMessage;
use App\Models\Webinar;
use App\Models\WebinarRegistration;
use App\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DispatchWebinarRegistrationMessagesActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_transactional_opt_in_is_only_scheduled_once_per_contact_scope_and_purpose(): void
    {
        Queue::fake();

        $series = WebinarSeries::factory()->create();

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $contact = Contact::factory()->create([
            'phone' => '6155551212',
        ]);

        $registrationOne = WebinarRegistration::query()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'pending',
            'source' => 'test',
            'first_name' => $contact->first_name ?? 'Test',
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'registered_at' => now(),
            'meta' => [],
        ]);

        $registrationTwo = WebinarRegistration::query()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'pending',
            'source' => 'test',
            'first_name' => $contact->first_name ?? 'Test',
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'registered_at' => now(),
            'meta' => [],
        ]);

        $action = app(DispatchWebinarRegistrationMessagesAction::class);

        $action->handle($registrationOne);
        $action->handle($registrationTwo);

        $this->assertEquals(
            1,
            ScheduledMessage::query()
                ->where('channel', 'sms')
                ->where('scope', 'webinar')
                ->where('purpose', 'transactional')
                ->where('message_type', 'webinar_transactional_opt_in')
                ->count()
        );

        Queue::assertPushed(SendScheduledMessageJob::class);
    }

    public function test_webinar_reminder_timing_comes_from_scope_config(): void
    {
        Queue::fake();

        config()->set('messaging.sms.webinar.reminders.variants.24_hours.offset_minutes_before_start', 60);
        config()->set('messaging.email.webinar.reminders.enabled', false);

        $series = WebinarSeries::factory()->create();

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addHours(3)->startOfMinute(),
        ]);

        $contact = Contact::factory()->create([
            'phone' => '6155551212',
        ]);

        $registration = WebinarRegistration::query()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'pending',
            'source' => 'test',
            'first_name' => $contact->first_name ?? 'Test',
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'registered_at' => now(),
            'meta' => [],
        ]);

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $this->assertDatabaseHas('scheduled_messages', [
            'channel' => 'sms',
            'scope' => 'webinar',
            'purpose' => 'transactional',
            'message_type' => 'reminder_24h',
            'send_at' => $webinar->starts_at->copy()->subMinutes(60),
        ]);
    }
}
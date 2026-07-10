<?php

namespace Tests\Feature\Webinars;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Actions\SyncWebinarScheduleProfilesAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarScheduleProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_creates_db_owned_webinar_schedule_profiles_and_items(): void
    {
        Config::set('webinars.schedule_profiles', [
            'test_profile' => [
                'name' => 'Test Profile',
                'is_default' => true,
                'items' => [
                    [
                        'key' => 'email_confirmation',
                        'context_key' => 'confirmation',
                        'channel' => 'email',
                        'purpose' => 'transactional',
                        'scope' => 'webinar',
                        'surface' => 'webinar_registrations',
                        'message_type' => 'confirmation',
                        'dispatch_key' => 'registration_created',
                        'message_template_key' => 'confirmation',
                        'timing' => 'scheduled',
                        'schedule' => ['type' => 'delay', 'minutes' => 5],
                    ],
                ],
            ],
        ]);

        $result = app(SyncWebinarScheduleProfilesAction::class)->handle();

        $this->assertSame(1, $result['profiles_created']);
        $this->assertSame(1, $result['items_created']);
        $this->assertDatabaseHas('webinar_schedule_profiles', [
            'key' => 'test_profile',
            'name' => 'Test Profile',
            'is_default' => true,
            'source' => 'config',
        ]);
        $this->assertDatabaseHas('webinar_schedule_profile_items', [
            'key' => 'email_confirmation',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'timing' => 'scheduled',
        ]);
    }

    public function test_series_schedule_profile_controls_future_registration_message_timing(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-07 12:00:00');
        $this->configureRegistrationMessages();
        $this->configureChannelAvailability();

        $profile = WebinarScheduleProfile::factory()->create(['key' => 'fast', 'name' => 'Fast', 'is_default' => false]);
        WebinarScheduleProfileItem::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
            'key' => 'email_confirmation_fast',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_template_key' => 'confirmation',
            'timing' => 'scheduled',
            'schedule' => ['type' => 'delay', 'minutes' => 2],
        ]);
        WebinarScheduleProfileItem::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
            'key' => 'email_reminder_disabled',
            'context_key' => 'reminders',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'reminder',
            'dispatch_key' => 'registration_created',
            'message_template_key' => 'reminder',
            'is_enabled' => false,
            'timing' => 'scheduled',
            'schedule' => ['type' => 'anchored', 'minutes' => -30],
        ]);

        $series = WebinarSeries::factory()->create(['webinar_schedule_profile_id' => $profile->getKey()]);
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDay(),
        ]);
        $registration = $this->registration($webinar, $this->contactWithConsent());

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $confirmation = ScheduledMessage::query()->where('message_type', 'confirmation')->firstOrFail();

        $this->assertTrue($confirmation->send_at->equalTo(now()->addMinutes(2)));
        $this->assertSame('fast', data_get($confirmation->meta, 'webinar_schedule_profile.key'));
        $this->assertArrayHasKey('webinar', $confirmation->payload['tokens']);
        $this->assertArrayHasKey('webinar_series', $confirmation->payload['tokens']);
        $this->assertArrayNotHasKey('items', $confirmation->payload['tokens']['webinar_series']);
        $this->assertArrayNotHasKey('webinar_schedule_profile', $confirmation->payload['tokens']['webinar_series']);
        $this->assertArrayNotHasKey('webinar_schedule_profile', $confirmation->payload['context']['webinar_series']);
        $this->assertDatabaseMissing('scheduled_messages', ['message_type' => 'reminder']);
        Queue::assertPushed(SendScheduledMessageJob::class, 1);
    }

    public function test_operator_can_choose_series_schedule_profile(): void
    {
        config()->set('modules.enabled', ['webinars']);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $user = User::factory()->create();
        $profile = WebinarScheduleProfile::factory()->create(['name' => 'Fast Schedule']);
        $series = WebinarSeries::factory()->create();

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/webinar-series/'.$series->getKey().'/schedule-profile', [
                'webinar_schedule_profile_id' => $profile->getKey(),
            ])
            ->assertRedirect(route('crm.webinar-series.index'));

        $this->assertDatabaseHas('webinar_series', [
            'id' => $series->getKey(),
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);
    }

    private function configureRegistrationMessages(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Registered.',
                ],
            ],
            'reminder' => [
                'key' => 'reminder',
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'reminders',
                'payload' => [
                    'subject' => 'Reminder',
                    'body' => 'Reminder.',
                ],
            ],
        ]);
    }

    private function configureChannelAvailability(): void
    {
        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => ['webinar_registrations' => true],
            'purpose_scopes' => ['transactional:webinar' => true],
        ]);
    }

    private function contactWithConsent(): Contact
    {
        $contact = Contact::factory()->create(['email' => 'lead@example.com']);

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        return $contact;
    }

    private function registration(Webinar $webinar, Contact $contact): WebinarRegistration
    {
        return WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $webinar->getKey(),
            'webinar_slug' => $webinar->slug,
            'registered_at' => now(),
            'meta' => ['accepted_channels' => ['transactional' => ['email']]],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}

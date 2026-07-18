<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Jobs\PostEvent\RetryWebinarRegistrationFollowUpJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WebinarFollowUpOutcomeAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set(
            'webinars.message_areas',
            require base_path('config/webinars/message_areas.php'),
        );
        Config::set(
            'webinars.post_event',
            require base_path('config/webinars/post_event.php'),
        );
        Config::set('webinars.post_event.outcome_messages.channels', ['email']);
        Config::set('webinars.post_event.outcome_messages.conditions', []);
        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => ['webinar_registrations' => true],
            'purpose_scopes' => ['transactional:webinar' => true],
        ]);

        $this->configurePostEventMessage();
    }

    public function test_scheduled_messages_create_a_durable_idempotent_registration_outcome(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        [$webinar, $registration, $contact] = $this->registration();
        $scheduledMessage = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'context_type' => $registration->getMorphClass(),
                'context_id' => $registration->getKey(),
            ]);

        $dispatch = Mockery::mock(DispatchMessageAction::class);
        $dispatch->shouldReceive('handle')
            ->once()
            ->andReturn([$scheduledMessage]);
        app()->instance(DispatchMessageAction::class, $dispatch);

        $provider = $this->provider();
        $action = app(DispatchPostWebinarFollowUpsAction::class);

        $this->assertTrue($action->execute($provider, $webinar, 'webinar.recording_completed'));
        $this->assertTrue($action->execute($provider, $webinar->fresh(), 'webinar.recording_completed'));

        $registration->refresh();
        $webinar->refresh();

        $this->assertSame('scheduled', data_get($registration->meta, 'post_event_follow_up.status'));
        $this->assertSame('missed', data_get($registration->meta, 'post_event_follow_up.outcome'));
        $this->assertSame(1, data_get($registration->meta, 'post_event_follow_up.attempts'));
        $this->assertSame(
            [$scheduledMessage->getKey()],
            data_get($registration->meta, 'post_event_follow_up.scheduled_message_ids'),
        );
        $this->assertNotNull(data_get($webinar->meta, 'normalized.post_event.follow_ups_dispatched_at'));
        $this->assertTrue(data_get($webinar->meta, 'normalized.post_event.follow_up_summary.complete'));
        $this->assertSame(1, data_get($webinar->meta, 'normalized.post_event.follow_up_summary.scheduled'));
    }

    public function test_zero_message_dispatch_is_an_explicit_terminal_outcome(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        [$webinar, $registration] = $this->registration();

        $dispatch = Mockery::mock(DispatchMessageAction::class);
        $dispatch->shouldReceive('handle')
            ->once()
            ->andReturn([]);
        app()->instance(DispatchMessageAction::class, $dispatch);

        $this->assertTrue(app(DispatchPostWebinarFollowUpsAction::class)->execute(
            $this->provider(),
            $webinar,
            'webinar.recording_completed',
        ));

        $registration->refresh();
        $webinar->refresh();

        $this->assertSame(
            'not_applicable',
            data_get($registration->meta, 'post_event_follow_up.status'),
        );
        $this->assertSame(
            'no_channels_eligible',
            data_get($registration->meta, 'post_event_follow_up.reason'),
        );
        $this->assertSame(
            'messaging_planning_gate_rejected',
            data_get($registration->meta, 'post_event_follow_up.channels.email.reason'),
        );
        $this->assertNotNull(data_get($webinar->meta, 'normalized.post_event.follow_ups_dispatched_at'));
        $this->assertSame(1, data_get($webinar->meta, 'normalized.post_event.follow_up_summary.not_applicable'));
    }

    public function test_missing_definition_is_failed_and_a_later_retry_can_complete(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        Config::set('messaging.email.definitions.transactional.webinar', []);

        [$webinar, $registration, $contact] = $this->registration();
        $scheduledMessage = ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'context_type' => $registration->getMorphClass(),
                'context_id' => $registration->getKey(),
            ]);

        $dispatch = Mockery::mock(DispatchMessageAction::class);
        $dispatch->shouldReceive('handle')
            ->once()
            ->andReturn([$scheduledMessage]);
        app()->instance(DispatchMessageAction::class, $dispatch);

        $provider = $this->provider();
        $action = app(DispatchPostWebinarFollowUpsAction::class);

        $this->assertFalse($action->execute($provider, $webinar, 'webinar.recording_completed'));

        $registration->refresh();
        $webinar->refresh();

        $this->assertSame('failed', data_get($registration->meta, 'post_event_follow_up.status'));
        $this->assertSame(
            'message_definition_unavailable',
            data_get($registration->meta, 'post_event_follow_up.failure_reason'),
        );
        $this->assertNull(data_get($webinar->meta, 'normalized.post_event.follow_ups_dispatched_at'));
        $this->assertFalse(data_get($webinar->meta, 'normalized.post_event.follow_up_summary.complete'));
        $this->assertNotNull(data_get($webinar->meta, 'automation_events.webinar_ended_recorded_at'));

        $this->configureEmailDefinitions();

        $this->assertTrue($action->execute($provider, $webinar, 'webinar.recording_completed'));

        $registration->refresh();
        $webinar->refresh();

        $this->assertSame('scheduled', data_get($registration->meta, 'post_event_follow_up.status'));
        $this->assertSame(2, data_get($registration->meta, 'post_event_follow_up.attempts'));
        $this->assertNotNull(data_get($webinar->meta, 'normalized.post_event.follow_ups_dispatched_at'));
    }

    public function test_cancelled_registration_is_explicitly_not_applicable(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        [$webinar, $registration] = $this->registration([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $dispatch = Mockery::mock(DispatchMessageAction::class);
        $dispatch->shouldReceive('handle')->never();
        app()->instance(DispatchMessageAction::class, $dispatch);

        $this->assertTrue(app(DispatchPostWebinarFollowUpsAction::class)->execute(
            $this->provider(),
            $webinar,
            'webinar.recording_completed',
        ));

        $registration->refresh();

        $this->assertSame(
            'not_applicable',
            data_get($registration->meta, 'post_event_follow_up.status'),
        );
        $this->assertSame(
            'registration_cancelled',
            data_get($registration->meta, 'post_event_follow_up.reason'),
        );
    }

    public function test_crm_surfaces_a_failed_outcome_and_queues_a_registration_retry(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        [$webinar, $registration] = $this->registration([
            'meta' => [
                'post_event_follow_up' => [
                    'status' => 'failed',
                    'outcome' => 'missed',
                    'attempts' => 5,
                    'failure_reason' => 'message_definition_unavailable',
                ],
            ],
        ]);
        $webinar->forceFill(['ends_at' => now()->subHour()])->save();

        $indexUrl = route('crm.webinar-series.index', ['archived' => 1]);

        $this->actingAs($user)
            ->get($indexUrl)
            ->assertOk()
            ->assertSee($webinar->title)
            ->assertSee('1 follow-up planning failure')
            ->assertSee('Message Definition Unavailable')
            ->assertSee('Retry follow-up planning');

        $this->actingAs($user)
            ->from($indexUrl)
            ->post(route('crm.webinar-registrations.follow-up.retry', $registration))
            ->assertRedirect($indexUrl)
            ->assertSessionHas(
                'success',
                'The post-webinar follow-up retry has been queued.',
            );

        Queue::assertPushed(
            RetryWebinarRegistrationFollowUpJob::class,
            fn (RetryWebinarRegistrationFollowUpJob $job): bool =>
                $job->registrationId === $registration->getKey(),
        );
    }

    /**
     * @param array<string, mixed> $registrationOverrides
     * @return array{Webinar, WebinarRegistration, Contact}
     */
    private function registration(array $registrationOverrides = []): array
    {
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => 'provider-webinar-123',
            'playback_url' => 'https://example.test/replay',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'meta' => [],
        ]);
        $contact = Contact::factory()->create([
            'email' => 'registrant@example.test',
        ]);
        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($contact)
            ->create(array_replace_recursive([
                'status' => 'missed',
                'attended_at' => null,
                'cancelled_at' => null,
                'meta' => [],
            ], $registrationOverrides));

        return [$webinar, $registration, $contact];
    }

    private function provider(): WebinarProvider
    {
        $provider = Mockery::mock(WebinarProvider::class);
        $provider->shouldReceive('key')
            ->zeroOrMoreTimes()
            ->andReturn('zoom');

        return $provider;
    }

    private function configurePostEventMessage(): void
    {
        $this->configureEmailDefinitions();

        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'follow_up_outcome_test_profile',
            'name' => 'Follow-up outcome test profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach (['post_attended', 'post_missed'] as $messageType) {
            WebinarScheduleProfileItem::factory()->create([
                'webinar_schedule_profile_id' => $profile->getKey(),
                'key' => 'email_'.$messageType,
                'context_key' => $messageType,
                'channel' => MessageChannel::Email->value,
                'purpose' => MessagePurpose::Transactional->value,
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => $messageType,
                'dispatch_key' => 'webinar_ended',
                'message_template_key' => $messageType,
                'timing' => 'immediate',
                'schedule' => null,
                'conditions' => [],
                'is_enabled' => true,
                'is_active' => true,
            ]);
        }
    }

    private function configureEmailDefinitions(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'post_attended' => [
                'key' => 'post_attended',
                'dispatch_key' => 'webinar_ended',
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'Thanks for attending',
                    'body' => 'Replay: {webinar_playback_url}',
                ],
            ],
            'post_missed' => [
                'key' => 'post_missed',
                'dispatch_key' => 'webinar_ended',
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'Sorry we missed you',
                    'body' => 'Replay: {webinar_playback_url}',
                ],
            ],
        ]);

    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
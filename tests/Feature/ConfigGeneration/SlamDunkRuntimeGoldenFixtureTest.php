<?php

namespace Tests\Feature\ConfigGeneration;

use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Actions\StartFlowRoutesFromAutomationEventAction;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\ConsentDomainRegistry;
use App\Modules\Webinars\Actions\DispatchWebinarRegistrationMessagesAction;
use App\Modules\Webinars\Actions\DispatchWebinarWaitlistMessagesAction;
use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class SlamDunkRuntimeGoldenFixtureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, array{environment: string|false, env_exists: bool, env: mixed, server_exists: bool, server: mixed}>
     */
    private static array $originalEnvironment = [];

    public function createApplication(): Application
    {
        $this->setBootstrapEnvironment('CLIENT_KEY', 'slam-dunk-crm');
        $this->setBootstrapEnvironment('CLIENT_PRESET', '');

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-11 12:00:00');
        Queue::fake();

        $this->configureChannelAvailability();

        $this->assertSame(0, Artisan::call('presets:sync', [
            'preset' => 'mortgage',
        ]), Artisan::output());
    }

    public function test_registration_and_waitlist_dispatch_use_the_synced_slam_dunk_profile_and_templates(): void
    {
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDays(14),
            'registration_url' => 'https://zoom.example.test/register/runtime-golden',
        ]);

        $contact = $this->contactWithConsent([
            ['email', 'transactional', 'webinar'],
            ['sms', 'transactional', 'webinar'],
            ['email', 'marketing', 'webinar_waitlist'],
            ['sms', 'marketing', 'webinar_waitlist'],
        ]);

        $registration = WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $webinar->getKey(),
            'webinar_slug' => $webinar->slug,
            'registered_at' => now(),
            'meta' => [
                'accepted_channels' => [
                    'transactional' => ['email', 'sms'],
                ],
            ],
        ]);

        app(DispatchWebinarRegistrationMessagesAction::class)->handle($registration);

        $registrationMessages = ScheduledMessage::query()
            ->where('context_type', $registration->getMorphClass())
            ->where('context_id', $registration->getKey())
            ->get();

        $this->assertCount(14, $registrationMessages);
        $this->assertSame(7, $registrationMessages->where('channel', 'email')->count());
        $this->assertSame(7, $registrationMessages->where('channel', 'sms')->count());
        $this->assertSame(
            ['confirmation', 'reminder'],
            $registrationMessages->pluck('message_type')->unique()->sort()->values()->all(),
        );
        $this->assertTrue($registrationMessages->every(
            fn (ScheduledMessage $message): bool => data_get($message->meta, 'webinar_schedule_profile.key') === 'full_10_day'
        ));

        $signup = WebinarWaitlistSignup::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_series_id' => $series->getKey(),
            'notified_at' => null,
            'meta' => [
                'accepted_channels' => [
                    'marketing' => ['email', 'sms'],
                ],
            ],
        ]);

        app(DispatchWebinarWaitlistMessagesAction::class)->handle($webinar);

        $waitlistMessages = ScheduledMessage::query()
            ->where('context_type', $signup->getMorphClass())
            ->where('context_id', $signup->getKey())
            ->get();

        $this->assertCount(2, $waitlistMessages);
        $this->assertEqualsCanonicalizing(['email', 'sms'], $waitlistMessages->pluck('channel')->all());
        $this->assertSame(['alert'], $waitlistMessages->pluck('message_type')->unique()->values()->all());
        $this->assertNotNull($signup->refresh()->notified_at);
    }

    public function test_post_event_follow_ups_use_real_attended_and_missed_definitions(): void
    {
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'playback_url' => 'https://zoom.example.test/recording/runtime-golden',
            'meta' => [
                'automation_events' => [
                    'webinar_ended_recorded_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $attended = $this->registrationWithTransactionalConsent($webinar, true);
        $missed = $this->registrationWithTransactionalConsent($webinar, false);

        $provider = Mockery::mock(WebinarProvider::class);

        $this->assertTrue(app(DispatchPostWebinarFollowUpsAction::class)->execute(
            provider: $provider,
            webinar: $webinar,
            event: 'webinar.recording_completed',
        ));

        $attendedMessages = ScheduledMessage::query()
            ->where('context_type', $attended->getMorphClass())
            ->where('context_id', $attended->getKey())
            ->get();
        $missedMessages = ScheduledMessage::query()
            ->where('context_type', $missed->getMorphClass())
            ->where('context_id', $missed->getKey())
            ->get();

        $this->assertCount(2, $attendedMessages);
        $this->assertCount(2, $missedMessages);
        $this->assertSame(['post_attended'], $attendedMessages->pluck('message_type')->unique()->values()->all());
        $this->assertSame(['post_missed'], $missedMessages->pluck('message_type')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['email', 'sms'], $attendedMessages->pluck('channel')->all());
        $this->assertEqualsCanonicalizing(['email', 'sms'], $missedMessages->pluck('channel')->all());
        $this->assertTrue($attendedMessages->concat($missedMessages)->every(
            fn (ScheduledMessage $message): bool => data_get($message->payload, 'tokens.webinar_playback_url') === $webinar->playback_url
        ));
    }

    public function test_attended_event_changes_status_and_enrolls_the_real_attended_campaign(): void
    {
        $this->assertOutcomeRuntime(
            eventKey: 'webinar.attended',
            statusKey: 'attended_webinar',
            campaignKey: 'webinar_attended_nurture',
            expectedScheduledChannels: ['email'],
            expectedDelayMinutes: 2880,
        );
    }

    public function test_missed_event_changes_status_and_enrolls_the_real_missed_campaign(): void
    {
        $this->assertOutcomeRuntime(
            eventKey: 'webinar.missed',
            statusKey: 'missed_webinar',
            campaignKey: 'webinar_missed_nurture',
            expectedScheduledChannels: ['email', 'sms'],
            expectedDelayMinutes: 120,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();

        $this->restoreBootstrapEnvironment();
    }

    /**
     * @param array<int, string> $expectedScheduledChannels
     */
    private function assertOutcomeRuntime(
        string $eventKey,
        string $statusKey,
        string $campaignKey,
        array $expectedScheduledChannels,
        int $expectedDelayMinutes,
    ): void {
        $contact = $this->contactWithConsent([
            ['email', 'marketing', 'webinar_nurture'],
            ['sms', 'marketing', 'webinar_nurture'],
        ]);

        $registration = WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'registered_at' => now()->subDay(),
        ]);

        app(StartFlowRoutesFromAutomationEventAction::class)->handle(
            FlowRouteExternalEvent::make(
                name: $eventKey,
                contactId: $contact->getKey(),
                subjectType: $registration->getMorphClass(),
                subjectId: $registration->getKey(),
                occurredAt: now(),
                payload: [
                    'webinar_registration' => [
                        'id' => $registration->getKey(),
                    ],
                ],
            ),
        );

        $status = ContactStatus::query()->where('key', $statusKey)->firstOrFail();

        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $status->getKey(),
        ]);

        $enrollment = CampaignEnrollment::query()
            ->where('contact_id', $contact->getKey())
            ->where('campaign_key', $campaignKey)
            ->firstOrFail();

        $routeIds = FlowRoute::query()
            ->where('trigger_type', FlowRoute::TRIGGER_AUTOMATION_EVENT)
            ->where('trigger_key', $eventKey)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $this->assertCount(2, $routeIds);
        $this->assertEqualsCanonicalizing(
            $routeIds,
            ContactFlowRouteProgress::query()
                ->where('contact_id', $contact->getKey())
                ->where('subject_type', $registration->getMorphClass())
                ->where('subject_id', $registration->getKey())
                ->pluck('flow_route_id')
                ->all(),
        );

        $messages = ScheduledMessage::query()
            ->where('meta->campaign_enrollment_id', $enrollment->getKey())
            ->get();

        $this->assertCount(count($expectedScheduledChannels), $messages);
        $this->assertEqualsCanonicalizing($expectedScheduledChannels, $messages->pluck('channel')->all());
        $this->assertTrue($messages->every(
            fn (ScheduledMessage $message): bool => $message->scope === 'webinar_nurture'
                && data_get($message->meta, 'campaign_key') === $campaignKey
                && $message->flow_route_progress_id !== null
                && $message->flow_route_id !== null
                && $message->flow_route_point_id !== null
                && $message->send_at->equalTo(now()->addMinutes($expectedDelayMinutes))
        ));

        $this->assertNotNull($enrollment->flow_route_progress_id);
        $this->assertNotNull($enrollment->flow_route_id);
        $this->assertNotNull($enrollment->flow_route_point_id);
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string}> $consents
     */
    private function contactWithConsent(array $consents): Contact
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Runtime',
            'last_name' => 'Golden',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+15555550123',
        ]);

        $consentDomains = app(ConsentDomainRegistry::class);

        foreach ($consents as [$channel, $purpose, $scope]) {
            MessageConsent::query()->create([
                'contact_id' => $contact->getKey(),
                'channel' => $channel,
                'purpose' => $purpose,
                'scope' => $consentDomains->domainForScope($scope),
                'consented_at' => now()->subMinute(),
                'source' => 'runtime_golden_fixture',
            ]);
        }

        return $contact;
    }

    private function registrationWithTransactionalConsent(
        Webinar $webinar,
        bool $attended,
    ): WebinarRegistration {
        $contact = $this->contactWithConsent([
            ['email', 'transactional', 'webinar'],
            ['sms', 'transactional', 'webinar'],
        ]);

        return WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $webinar->getKey(),
            'webinar_slug' => $webinar->slug,
            'registered_at' => now()->subDays(2),
            'attended_at' => $attended ? now()->subHour() : null,
            'status' => $attended ? 'attended' : 'missed',
            'meta' => [
                'accepted_channels' => [
                    'transactional' => ['email', 'sms'],
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
            'surfaces' => [
                'webinar_registrations' => true,
                'webinar_waitlists' => true,
                'campaigns' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_waitlist' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_registrations' => true,
                'webinar_waitlists' => true,
                'campaigns' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_waitlist' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);
    }

    private function setBootstrapEnvironment(string $key, string $value): void
    {
        if (! array_key_exists($key, self::$originalEnvironment)) {
            self::$originalEnvironment[$key] = [
                'environment' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function restoreBootstrapEnvironment(): void
    {
        foreach (self::$originalEnvironment as $key => $original) {
            $original['environment'] === false
                ? putenv($key)
                : putenv("{$key}={$original['environment']}");

            if ($original['env_exists']) {
                $_ENV[$key] = $original['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($original['server_exists']) {
                $_SERVER[$key] = $original['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }

        self::$originalEnvironment = [];
    }
}

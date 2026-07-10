<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Services\WebinarMessageReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarMessageReadinessServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('messaging.email', []);
        Config::set('messaging.sms', []);
        Config::set('webinars.post_event.outcome_messages.enabled', false);

        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'webinar_registrations' => true,
                'webinar_waitlists' => false,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_waitlist' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_registrations' => false,
                'webinar_waitlists' => false,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_waitlist' => true,
            ],
        ]);
    }

    public function test_it_is_ready_when_required_contexts_resolve_through_config_fallback(): void
    {
        $this->configureRequiredRegistrationDefinitions();

        $readiness = app(WebinarMessageReadinessService::class)->resolve();

        $this->assertSame(WebinarMessageReadinessService::STATUS_READY, $readiness['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_READY, $readiness['contexts']['confirmation']['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_READY, $readiness['contexts']['registration_opt_in']['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_READY, $readiness['contexts']['reminders']['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_OPTIONAL, $readiness['contexts']['waitlist']['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_OPTIONAL, $readiness['contexts']['waitlist_opt_in']['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_OPTIONAL, $readiness['contexts']['post_attended']['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_OPTIONAL, $readiness['contexts']['post_missed']['status']);
        $this->assertSame(['Config fallback'], $readiness['contexts']['confirmation']['channels'][0]['source_labels']);
    }

    public function test_it_needs_attention_when_a_required_message_context_does_not_resolve(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => $this->definition(
                dispatchKey: 'registration_created',
                subject: 'Registered',
            ),
            'opt_in' => $this->definition(
                dispatchKey: 'consent_granted',
                subject: 'Subscribed',
            ),
        ]);

        $readiness = app(WebinarMessageReadinessService::class)->resolve();

        $this->assertSame(WebinarMessageReadinessService::STATUS_NEEDS_ATTENTION, $readiness['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_NEEDS_ATTENTION, $readiness['contexts']['reminders']['status']);
        $this->assertSame(1, $readiness['counts']['needs_attention']);
    }

    public function test_it_needs_attention_when_registration_opt_in_is_missing_from_available_registration_messaging(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => $this->definition(
                dispatchKey: 'registration_created',
                subject: 'Registered',
            ),
            'reminder' => $this->definition(
                dispatchKey: 'registration_created',
                subject: 'Reminder',
            ),
        ]);

        $readiness = app(WebinarMessageReadinessService::class)->resolve();

        $this->assertSame(WebinarMessageReadinessService::STATUS_NEEDS_ATTENTION, $readiness['status']);
        $this->assertSame(
            WebinarMessageReadinessService::STATUS_NEEDS_ATTENTION,
            $readiness['contexts']['registration_opt_in']['status'],
        );
    }

    public function test_waitlist_opt_in_becomes_required_when_waitlist_messaging_is_available(): void
    {
        $this->configureRequiredRegistrationDefinitions();

        Config::set('messaging.channel_availability.email.surfaces.webinar_waitlists', true);

        Config::set('messaging.email.marketing.webinar_waitlist', [
            'alert' => $this->definition(
                dispatchKey: 'webinar_added',
                subject: 'New webinar',
            ),
        ]);

        $readiness = app(WebinarMessageReadinessService::class)->resolve();

        $this->assertSame(WebinarMessageReadinessService::STATUS_NEEDS_ATTENTION, $readiness['status']);
        $this->assertSame(
            WebinarMessageReadinessService::STATUS_NEEDS_ATTENTION,
            $readiness['contexts']['waitlist_opt_in']['status'],
        );
    }

    public function test_it_treats_a_context_disabled_by_the_active_default_profile_as_optional_disabled(): void
    {
        $this->configureRequiredRegistrationDefinitions();

        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'default_profile',
            'name' => 'Default profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
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
            'is_active' => true,
            'timing' => 'scheduled',
            'schedule' => [
                'type' => 'anchored',
                'minutes' => -30,
            ],
        ]);

        $readiness = app(WebinarMessageReadinessService::class)->resolve();

        $this->assertSame(WebinarMessageReadinessService::STATUS_READY, $readiness['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_OPTIONAL, $readiness['contexts']['reminders']['status']);
        $this->assertSame('Default profile', $readiness['profile_names'][0]);
    }

    private function configureRequiredRegistrationDefinitions(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => $this->definition(
                dispatchKey: 'registration_created',
                subject: 'Registered',
            ),
            'opt_in' => $this->definition(
                dispatchKey: 'consent_granted',
                subject: 'Subscribed',
            ),
            'reminder' => $this->definition(
                dispatchKey: 'registration_created',
                subject: 'Reminder',
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $dispatchKey, string $subject): array
    {
        return [
            'dispatch_key' => $dispatchKey,
            'payload_class' => EmailPayload::class,
            'queue' => 'notifications',
            'payload' => [
                'subject' => $subject,
                'body' => $subject.'.',
            ],
        ];
    }
}

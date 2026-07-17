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
        Config::set(
            'webinars.message_areas',
            require base_path('config/webinars/message_areas.php'),
        );
        Config::set('webinars.message_areas.post_attended.enabled', false);
        Config::set('webinars.message_areas.post_missed.enabled', false);

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
        $this->assertSame('Sent separately', $readiness['contexts']['registration_opt_in']['status_label']);
        $this->assertSame(
            'sent_separately',
            $readiness['contexts']['registration_opt_in']['channels'][0]['delivery_mode'],
        );
        $this->assertSame(WebinarMessageReadinessService::STATUS_READY, $readiness['contexts']['reminders']['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_OPTIONAL, $readiness['contexts']['waitlist']['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_OPTIONAL, $readiness['contexts']['waitlist_opt_in']['status']);
        $this->assertArrayNotHasKey('post_attended', $readiness['contexts']);
        $this->assertArrayNotHasKey('post_missed', $readiness['contexts']);
        $this->assertSame(['Config fallback'], $readiness['contexts']['confirmation']['channels'][0]['source_labels']);
    }


    public function test_intentionally_disabled_message_area_is_omitted_instead_of_reported_missing(): void
    {
        Config::set('webinars.message_areas.reminders.enabled', false);
        Config::set('messaging.email.definitions.transactional.webinar', [
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

        $this->assertSame(WebinarMessageReadinessService::STATUS_READY, $readiness['status']);
        $this->assertArrayNotHasKey('reminders', $readiness['contexts']);
        $this->assertSame(0, $readiness['counts']['needs_attention']);
    }

    public function test_it_needs_attention_when_a_required_message_context_does_not_resolve(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => $this->definition(
                dispatchKey: 'registration_created',
                subject: 'Registered',
            ),
        ]);

        $readiness = app(WebinarMessageReadinessService::class)->resolve();

        $this->assertSame(WebinarMessageReadinessService::STATUS_NEEDS_ATTENTION, $readiness['status']);
        $this->assertSame(WebinarMessageReadinessService::STATUS_NEEDS_ATTENTION, $readiness['contexts']['reminders']['status']);
        $this->assertSame(1, $readiness['counts']['needs_attention']);
    }

    public function test_registration_opt_in_is_ready_when_delivery_consolidation_includes_it_with_confirmation(): void
    {
        $this->configureRequiredRegistrationDefinitions();

        Config::set(
            'messaging.delivery_consolidation',
            require base_path('config/messaging/delivery_consolidation.php'),
        );
        Config::set('messaging.delivery_consolidation.policies.webinar_registration.enabled', true);

        $readiness = app(WebinarMessageReadinessService::class)->resolve();

        $this->assertSame(WebinarMessageReadinessService::STATUS_READY, $readiness['status']);
        $this->assertSame(
            WebinarMessageReadinessService::STATUS_READY,
            $readiness['contexts']['registration_opt_in']['status'],
        );
        $this->assertSame(
            'Included with confirmation',
            $readiness['contexts']['registration_opt_in']['status_label'],
        );
        $this->assertSame(
            'included_with_confirmation',
            $readiness['contexts']['registration_opt_in']['channels'][0]['delivery_mode'],
        );
    }

    public function test_waitlist_opt_in_uses_the_messaging_owned_standalone_definition(): void
    {
        $this->configureRequiredRegistrationDefinitions();

        Config::set('messaging.channel_availability.email.surfaces.webinar_waitlists', true);

        Config::set('messaging.email.definitions.marketing.webinar_waitlist', [
            'alert' => $this->definition(
                dispatchKey: 'webinar_added',
                subject: 'New webinar',
            ),
        ]);

        $readiness = app(WebinarMessageReadinessService::class)->resolve();

        $this->assertSame(WebinarMessageReadinessService::STATUS_READY, $readiness['status']);
        $this->assertSame(
            WebinarMessageReadinessService::STATUS_READY,
            $readiness['contexts']['waitlist_opt_in']['status'],
        );
        $this->assertSame('Sent separately', $readiness['contexts']['waitlist_opt_in']['status_label']);
        $this->assertSame(
            'sent_separately',
            $readiness['contexts']['waitlist_opt_in']['channels'][0]['delivery_mode'],
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
        Config::set('messaging.email.definitions.transactional.webinar', [
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

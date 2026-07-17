<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class WebinarMessageAreaRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set(
            'webinars.message_areas',
            require base_path('config/webinars/message_areas.php'),
        );
    }

    public function test_core_registry_exposes_the_configured_message_areas_in_display_order(): void
    {
        $keys = app(WebinarMessageAreaRegistry::class)
            ->enabled()
            ->keys()
            ->values()
            ->all();

        $this->assertSame([
            'confirmation',
            'registration_opt_in',
            'reminders',
            'waitlist',
            'waitlist_opt_in',
            'post_attended',
            'post_missed',
        ], $keys);
    }

    public function test_client_can_intentionally_disable_a_template_message_area(): void
    {
        Config::set('webinars.message_areas.reminders.enabled', false);

        $registry = app(WebinarMessageAreaRegistry::class);

        $this->assertFalse($registry->isEnabled('reminders'));
        $this->assertNotContains('reminders', $registry->enabled()->keys()->all());
    }

    public function test_profile_context_aliases_resolve_to_the_canonical_area_key(): void
    {
        $registry = app(WebinarMessageAreaRegistry::class);

        $this->assertSame('confirmation', $registry->canonicalKeyForContext('confirmations'));
        $this->assertSame('reminders', $registry->canonicalKeyForContext('reminder'));
    }

    public function test_runtime_definitions_are_filtered_by_enabled_message_areas(): void
    {
        Config::set('webinars.message_areas.confirmation.enabled', false);

        $definitions = [
            [
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'message_type' => 'confirmation',
                'dispatch_keys' => ['registration_created'],
            ],
            [
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'message_type' => 'reminder',
                'dispatch_keys' => ['registration_created'],
            ],
        ];

        $resolved = app(WebinarMessageAreaRegistry::class)->filterDefinitions(
            definitions: $definitions,
            surface: 'webinar_registrations',
        );

        $this->assertCount(1, $resolved);
        $this->assertSame('reminder', $resolved[0]['message_type']);
    }

    public function test_schedule_context_can_own_an_area_with_a_compatible_custom_message_type(): void
    {
        $area = app(WebinarMessageAreaRegistry::class)->areaForScheduleItem([
            'context_key' => 'waitlist',
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'surface' => 'webinar_waitlists',
            'message_type' => 'scheduled_notice',
            'dispatch_key' => 'webinar_added',
        ]);

        $this->assertSame('waitlist', $area?->key);
    }

    public function test_unknown_legacy_context_can_fall_back_to_exact_message_type(): void
    {
        $area = app(WebinarMessageAreaRegistry::class)->areaForScheduleItem([
            'context_key' => 'post_event',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'post_attended',
            'dispatch_key' => 'webinar_ended',
        ]);

        $this->assertSame('post_attended', $area?->key);
    }

    public function test_known_context_does_not_fall_through_to_a_different_message_area(): void
    {
        $area = app(WebinarMessageAreaRegistry::class)->areaForScheduleItem([
            'context_key' => 'reminders',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'post_attended',
            'dispatch_key' => 'webinar_ended',
        ]);

        $this->assertNull($area);
    }

    public function test_resolved_definition_uses_its_schedule_context_as_the_area_owner(): void
    {
        $owner = new \App\Modules\Webinars\Models\WebinarScheduleProfileItem([
            'context_key' => 'waitlist',
        ]);

        $area = app(WebinarMessageAreaRegistry::class)->areaForDefinition([
            'purpose' => 'marketing',
            'scope' => 'webinar_waitlist',
            'message_type' => 'scheduled_notice',
            'dispatch_keys' => ['webinar_added'],
            'behavior_owner' => $owner,
        ], 'webinar_waitlists');

        $this->assertSame('waitlist', $area?->key);
    }

    public function test_consent_acknowledgement_area_cannot_be_disabled_directly(): void
    {
        Config::set('webinars.message_areas.registration_opt_in.enabled', false);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be disabled directly');

        app(WebinarMessageAreaRegistry::class)->all();
    }

    public function test_consent_acknowledgement_area_cannot_opt_into_direct_disabling(): void
    {
        Config::set('webinars.message_areas.registration_opt_in.disableable', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must remain non-disableable');

        app(WebinarMessageAreaRegistry::class)->all();
    }

    public function test_profile_context_aliases_must_have_one_owner(): void
    {
        Config::set('webinars.message_areas.reminders.profile_context_keys', [
            'reminders',
            'confirmations',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is owned by both');

        app(WebinarMessageAreaRegistry::class)->all();
    }
}

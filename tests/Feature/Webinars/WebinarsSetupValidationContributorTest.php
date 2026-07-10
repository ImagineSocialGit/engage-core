<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Validation\WebinarsSetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarsSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('webinars.schedule_profiles', []);
        Config::set('messaging.email', []);
        Config::set('messaging.sms', []);

        $this->configureEmailAvailability();
    }

    public function test_it_accepts_valid_config_and_runtime_profile_state(): void
    {
        $this->configureValidMessagingDefinition();

        Config::set('webinars.schedule_profiles', [
            'test_profile' => [
                'name' => 'Test profile',
                'status' => 'active',
                'is_default' => true,
                'is_active' => true,
                'items' => [
                    $this->validConfigItem(),
                ],
            ],
        ]);

        $profile = $this->profile([
            'key' => 'test_profile',
            'is_default' => true,
        ]);

        $this->item($profile);

        $this->assertSame([], $this->findings());
    }

    public function test_it_reports_duplicate_normalized_config_keys_and_multiple_defaults(): void
    {
        Config::set('webinars.schedule_profiles', [
            'first-profile' => [
                'name' => 'First',
                'is_default' => true,
                'is_active' => true,
                'items' => [
                    $this->validConfigItem(['key' => 'email-reminder']),
                    $this->validConfigItem(['key' => 'email_reminder']),
                ],
            ],
            'first_profile' => [
                'name' => 'Second',
                'is_default' => true,
                'is_active' => true,
                'items' => [],
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('webinars.schedule_profiles.duplicate_profile_key', $codes);
        $this->assertContains('webinars.schedule_profiles.duplicate_item_key', $codes);
        $this->assertContains('webinars.schedule_profiles.multiple_active_defaults', $codes);
    }

    public function test_it_reports_invalid_config_item_timing_and_schedule(): void
    {
        Config::set('webinars.schedule_profiles', [
            'invalid_profile' => [
                'name' => 'Invalid profile',
                'items' => [
                    $this->validConfigItem([
                        'timing' => 'later',
                    ]),
                    $this->validConfigItem([
                        'key' => 'bad_schedule',
                        'schedule' => [
                            'type' => 'calendar_magic',
                            'minutes' => 'soon',
                        ],
                    ]),
                ],
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('webinars.schedule_profiles.timing_invalid', $codes);
        $this->assertContains('webinars.schedule_profiles.schedule_type_invalid', $codes);
        $this->assertContains('webinars.schedule_profiles.schedule_minutes_invalid', $codes);
    }

    public function test_it_reports_conflicting_active_default_profiles_in_db_state(): void
    {
        $this->profile([
            'key' => 'first_default',
            'is_default' => true,
        ]);

        $this->profile([
            'key' => 'second_default',
            'is_default' => true,
        ]);

        $this->assertContains(
            'webinars.schedule_profiles.runtime_multiple_active_defaults',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_it_reports_missing_messaging_definition_for_active_enabled_item(): void
    {
        $profile = $this->profile();
        $this->item($profile);

        $this->assertContains(
            'webinars.schedule_profiles.messaging_definition_missing',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_it_warns_when_channel_is_unavailable_for_surface_but_definition_resolves(): void
    {
        $this->configureValidMessagingDefinition();

        Config::set(
            'messaging.channel_availability.email.surfaces.webinar_registrations',
            false,
        );

        $profile = $this->profile();
        $this->item($profile);

        $findings = $this->findings();

        $warnings = array_values(array_filter(
            $findings,
            fn (array $finding): bool => $finding['code']
                === 'webinars.schedule_profiles.channel_unavailable_for_surface',
        ));

        $this->assertCount(1, $warnings);
        $this->assertSame(
            SetupValidationFinding::SEVERITY_WARNING,
            $warnings[0]['severity'],
        );

        $this->assertNotContains(
            'webinars.schedule_profiles.messaging_definition_missing',
            array_column($findings, 'code'),
        );
    }

    public function test_it_reports_selected_inactive_profile_and_missing_default_fallback(): void
    {
        $inactive = $this->profile([
            'key' => 'inactive_profile',
            'status' => WebinarScheduleProfile::STATUS_INACTIVE,
            'is_active' => false,
        ]);

        WebinarSeries::factory()->create([
            'webinar_schedule_profile_id' => $inactive->getKey(),
        ]);

        WebinarSeries::factory()->create([
            'webinar_schedule_profile_id' => null,
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('webinars.schedule_profiles.selected_profile_inactive', $codes);
        $this->assertContains('webinars.schedule_profiles.default_fallback_missing', $codes);
    }

    public function test_it_uses_webinar_specific_profile_without_requiring_default_fallback(): void
    {
        $profile = $this->profile([
            'key' => 'specific_profile',
            'is_default' => false,
        ]);

        $series = WebinarSeries::factory()->create([
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);

        Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'webinar_schedule_profile_id' => $profile->getKey(),
        ]);

        $this->assertNotContains(
            'webinars.schedule_profiles.default_fallback_missing',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_manager_resolves_tagged_webinars_contributor(): void
    {
        $this->profile([
            'key' => 'first_default',
            'is_default' => true,
        ]);

        $this->profile([
            'key' => 'second_default',
            'is_default' => true,
        ]);

        $result = app(SetupValidationManager::class)->validate();

        $this->assertContains(
            'webinars.schedule_profiles.runtime_multiple_active_defaults',
            array_map(
                fn (SetupValidationFinding $finding): string => $finding->code,
                $result->findings(),
            ),
        );
    }

    private function configureValidMessagingDefinition(): void
    {
        Config::set('messaging.email.transactional.webinar', [
            'confirmation' => [
                'key' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Registered',
                    'body' => 'Thanks',
                ],
            ],
        ]);
    }

    private function configureEmailAvailability(): void
    {
        Config::set('messaging.channel_availability.email.runtime_supported', true);
        Config::set('messaging.channel_availability.email.provider_enabled', true);
        Config::set('messaging.channel_availability.email.surfaces.webinar_registrations', true);
        Config::set('messaging.channel_availability.email.purpose_scopes', [
            'transactional:webinar' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validConfigItem(array $overrides = []): array
    {
        return array_replace([
            'key' => 'email_confirmation',
            'context_key' => 'confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_template_key' => 'confirmation',
            'source_config_path' => null,
            'timing' => 'scheduled',
            'schedule' => [
                'type' => 'delay',
                'minutes' => 5,
            ],
            'conditions' => [],
            'is_enabled' => true,
            'is_active' => true,
            'meta' => [],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function profile(array $overrides = []): WebinarScheduleProfile
    {
        return WebinarScheduleProfile::factory()->create(array_replace([
            'key' => 'runtime_profile',
            'name' => 'Runtime profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => false,
            'is_active' => true,
            'is_customized' => false,
            'meta' => [],
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function item(
        WebinarScheduleProfile $profile,
        array $overrides = [],
    ): WebinarScheduleProfileItem {
        return WebinarScheduleProfileItem::factory()->create(array_replace([
            'webinar_schedule_profile_id' => $profile->getKey(),
            'key' => 'email_confirmation',
            'label' => 'Email confirmation',
            'context_key' => 'confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'confirmation',
            'dispatch_key' => 'registration_created',
            'message_template_key' => 'confirmation',
            'source_config_path' => null,
            'is_enabled' => true,
            'is_active' => true,
            'is_customized' => false,
            'sort_order' => 10,
            'timing' => 'scheduled',
            'schedule' => [
                'type' => 'delay',
                'minutes' => 5,
            ],
            'conditions' => [],
            'meta' => [],
        ], $overrides));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findings(): array
    {
        return array_map(
            fn (SetupValidationFinding $finding): array => $finding->toArray(),
            iterator_to_array(
                app(WebinarsSetupValidationContributor::class)->findings(),
                false,
            ),
        );
    }
}

<?php

namespace Tests\Feature\Webinars;

use App\Integrations\Webinars\Zoom\ZoomMeetingProvider;
use App\Integrations\Webinars\Zoom\ZoomWebinarProvider;
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

        Config::set('app.webinar_url', 'https://webinar.example.test');
        Config::set('webinars.schedule_profiles', []);
        Config::set('messaging.email', []);
        Config::set('messaging.sms', []);

        $this->configureValidZoomReadiness();
        $this->configureMessageAreas();
        $this->configureEmailAvailability();
    }

    public function test_it_reports_a_scheme_less_public_webinar_url(): void
    {
        Config::set('app.webinar_url', 'webinar.example.test');

        $finding = collect($this->findings())
            ->firstWhere('code', 'webinars.public_url.invalid');

        $this->assertIsArray($finding);
        $this->assertSame('app', $finding['source']);
        $this->assertSame('app.webinar_url', $finding['path']);
    }

    public function test_it_reports_an_unsupported_public_webinar_url_scheme(): void
    {
        Config::set('app.webinar_url', 'ftp://webinar.example.test');

        $finding = collect($this->findings())
            ->firstWhere('code', 'webinars.public_url.invalid');

        $this->assertIsArray($finding);
        $this->assertSame('app.webinar_url', $finding['path']);
    }

    public function test_it_reports_missing_zoom_server_to_server_oauth_credentials(): void
    {
        Config::set('services.zoom.account_id', null);
        Config::set('services.zoom.client_id', '');
        Config::set('services.zoom.client_secret', null);

        $paths = collect($this->findings())
            ->where('code', 'webinars.zoom.oauth_credential_missing')
            ->pluck('path')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'services.zoom.account_id',
            'services.zoom.client_id',
            'services.zoom.client_secret',
        ], $paths);
    }

    public function test_it_reports_insecure_or_malformed_zoom_endpoints(): void
    {
        Config::set('webinars.providers.zoom.base_url', 'http://api.zoom.us/v2');
        Config::set('webinars.providers.zoom.oauth_url', 'zoom.us/oauth/token');

        $paths = collect($this->findings())
            ->where('code', 'webinars.zoom.endpoint_invalid')
            ->pluck('path')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'webinars.providers.zoom.base_url',
            'webinars.providers.zoom.oauth_url',
        ], $paths);
    }

    public function test_it_reports_a_missing_zoom_meeting_adapter_definition(): void
    {
        Config::set('webinars.providers.zoom.event_types.meeting', null);

        $finding = collect($this->findings())
            ->firstWhere('code', 'webinars.provider.event_type_missing');

        $this->assertIsArray($finding);
        $this->assertSame(
            'webinars.providers.zoom.event_types.meeting',
            $finding['path'],
        );
    }

    public function test_it_reports_a_zoom_adapter_that_does_not_implement_the_provider_contract(): void
    {
        Config::set(
            'webinars.providers.zoom.event_types.meeting.provider',
            \stdClass::class,
        );

        $finding = collect($this->findings())
            ->firstWhere('code', 'webinars.provider.event_type_contract_invalid');

        $this->assertIsArray($finding);
        $this->assertSame(
            'webinars.providers.zoom.event_types.meeting.provider',
            $finding['path'],
        );
    }

    public function test_it_reports_a_missing_required_zoom_meeting_webhook_mapping(): void
    {
        $events = Config::get('webinars.providers.zoom.webhook_events', []);
        unset($events['meeting.ended']);
        Config::set('webinars.providers.zoom.webhook_events', $events);

        $finding = collect($this->findings())
            ->firstWhere('code', 'webinars.zoom.webhook_event_mapping_invalid');

        $this->assertIsArray($finding);
        $this->assertSame(
            'webinars.providers.zoom.webhook_events.meeting.ended',
            $finding['path'],
        );
        $this->assertSame(
            'webinar.ended',
            data_get($finding, 'context.expected_mapping'),
        );
    }

    public function test_it_reports_missing_zoom_webhook_secret_and_invalid_timestamp_drift(): void
    {
        Config::set('services.zoom.webhook_secret', null);
        Config::set('services.zoom.max_timestamp_drift_seconds', 0);
        Config::set('webinars.providers.zoom.oauth_token_ttl_seconds', 3601);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('webinars.zoom.webhook_secret_missing', $codes);
        $this->assertContains(
            'webinars.zoom.webhook_timestamp_drift_invalid',
            $codes,
        );
        $this->assertContains('webinars.zoom.oauth_token_ttl_invalid', $codes);
    }

    public function test_it_accepts_complete_zoom_webinar_and_meeting_readiness_configuration(): void
    {
        $providerFindings = array_values(array_filter(
            $this->findings(),
            fn (array $finding): bool => str_starts_with(
                $finding['code'],
                'webinars.provider.',
            ) || str_starts_with(
                $finding['code'],
                'webinars.zoom.',
            ),
        ));

        $this->assertSame([], $providerFindings);
    }

    public function test_it_reports_invalid_message_area_configuration_as_a_setup_finding(): void
    {
        Config::set('webinars.message_areas.registration_opt_in', [
            'enabled' => false,
            'disableable' => false,
            'kind' => 'consent_acknowledgement',
            'label' => 'Registration opt-in confirmations',
            'description' => 'Consent acknowledgement.',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => 'webinar_registrations',
            'message_type' => 'opt_in',
            'dispatch_key' => 'consent_granted',
            'required' => 'registration_messaging_available',
            'usage_types' => [],
            'profile_context_keys' => [],
            'managed_by_messaging' => true,
            'sort_order' => 20,
        ]);

        $findings = $this->findings();

        $this->assertCount(1, $findings);
        $this->assertSame('webinars.message_areas.config_invalid', $findings[0]['code']);
        $this->assertSame('webinars.message_areas', $findings[0]['source']);
        $this->assertSame('webinars.message_areas', $findings[0]['path']);
        $this->assertStringContainsString('cannot be disabled directly', $findings[0]['message']);
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
                        'key' => 'bad_schedule_type',
                        'schedule' => [
                            'type' => 'calendar_magic',
                            'minutes' => 'soon',
                        ],
                    ]),
                    $this->validConfigItem([
                        'key' => 'bad_schedule_minutes',
                        'schedule' => [
                            'type' => 'delay',
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

    public function test_it_validates_next_day_at_schedule_time(): void
    {
        Config::set('webinars.schedule_profiles', [
            'valid_profile' => [
                'name' => 'Valid profile',
                'items' => [
                    $this->validConfigItem([
                        'key' => 'valid_next_day_at',
                        'schedule' => [
                            'type' => 'next_day_at',
                            'time' => '09:00',
                        ],
                    ]),
                ],
            ],
            'invalid_profile' => [
                'name' => 'Invalid profile',
                'items' => [
                    $this->validConfigItem([
                        'key' => 'invalid_next_day_at',
                        'schedule' => [
                            'type' => 'next_day_at',
                            'time' => '9am',
                        ],
                    ]),
                ],
            ],
        ]);

        $findings = $this->findings();
        $codes = array_column($findings, 'code');

        $this->assertContains(
            'webinars.schedule_profiles.schedule_time_invalid',
            $codes,
        );

        $validItemFindings = array_values(array_filter(
            $findings,
            fn (array $finding): bool => data_get(
                $finding,
                'context.item_key',
            ) === 'valid_next_day_at',
        ));

        $this->assertSame([], $validItemFindings);
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


    public function test_it_ignores_runtime_definition_and_availability_checks_for_disabled_message_area(): void
    {
        Config::set('webinars.message_areas.confirmation.enabled', false);
        Config::set(
            'messaging.channel_availability.email.surfaces.webinar_registrations',
            false,
        );

        Config::set('webinars.schedule_profiles', [
            'disabled_area_profile' => [
                'name' => 'Disabled area profile',
                'items' => [
                    $this->validConfigItem(),
                ],
            ],
        ]);

        $profile = $this->profile();
        $this->item($profile);

        $codes = array_column($this->findings(), 'code');

        $this->assertNotContains(
            'webinars.schedule_profiles.messaging_definition_missing',
            $codes,
        );
        $this->assertNotContains(
            'webinars.schedule_profiles.channel_unavailable_for_surface',
            $codes,
        );
        $this->assertNotContains(
            'webinars.schedule_profiles.message_area_unmapped',
            $codes,
        );
    }

    public function test_it_reports_config_and_runtime_items_that_do_not_map_to_a_message_area(): void
    {
        $unmapped = $this->validConfigItem([
            'key' => 'legacy_follow_up',
            'context_key' => 'legacy_follow_up',
            'message_type' => 'legacy_follow_up',
            'dispatch_key' => 'legacy_event',
            'message_template_key' => 'legacy_follow_up',
        ]);

        Config::set('webinars.schedule_profiles', [
            'unmapped_profile' => [
                'name' => 'Unmapped profile',
                'items' => [$unmapped],
            ],
        ]);

        $profile = $this->profile();
        $this->item($profile, [
            'key' => 'legacy_follow_up',
            'context_key' => 'legacy_follow_up',
            'message_type' => 'legacy_follow_up',
            'dispatch_key' => 'legacy_event',
            'message_template_key' => 'legacy_follow_up',
        ]);

        $unmappedFindings = array_values(array_filter(
            $this->findings(),
            fn (array $finding): bool => $finding['code']
                === 'webinars.schedule_profiles.message_area_unmapped',
        ));

        $this->assertCount(2, $unmappedFindings);
        $this->assertEqualsCanonicalizing([
            'webinars.schedule_profiles.unmapped_profile.items.0',
            'webinar_schedule_profile_items.'.$profile->items()->firstOrFail()->getKey(),
        ], array_column($unmappedFindings, 'path'));
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

    private function configureValidZoomReadiness(): void
    {
        Config::set('webinars.provider', 'zoom');
        Config::set('webinars.provider_event_type', 'webinar');
        Config::set('webinars.providers.zoom', [
            'provider' => ZoomWebinarProvider::class,
            'event_types' => [
                'webinar' => [
                    'label' => 'Webinar',
                    'provider' => ZoomWebinarProvider::class,
                ],
                'meeting' => [
                    'label' => 'Meeting',
                    'provider' => ZoomMeetingProvider::class,
                ],
            ],
            'base_url' => 'https://api.zoom.us/v2',
            'oauth_url' => 'https://zoom.us/oauth/token',
            'oauth_token_ttl_seconds' => 3500,
            'webhook_events' => [
                'webinar.ended' => 'webinar.ended',
                'webinar.completed' => 'webinar.ended',
                'meeting.ended' => 'webinar.ended',
                'recording.completed' => 'webinar.recording_completed',
            ],
        ]);

        Config::set('services.zoom.account_id', 'zoom-account');
        Config::set('services.zoom.client_id', 'zoom-client');
        Config::set('services.zoom.client_secret', 'zoom-secret');
        Config::set('services.zoom.webhook_secret', 'zoom-webhook-secret');
        Config::set('services.zoom.max_timestamp_drift_seconds', 300);
    }

    private function configureMessageAreas(): void
    {
        Config::set('webinars.message_areas', [
            'confirmation' => [
                'enabled' => true,
                'disableable' => true,
                'kind' => 'template',
                'label' => 'Registration confirmations',
                'description' => 'Sent after someone registers.',
                'purpose' => 'transactional',
                'scope' => 'webinar',
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'dispatch_key' => 'registration_created',
                'required' => true,
                'usage_types' => ['webinar_confirmation'],
                'profile_context_keys' => ['confirmation', 'confirmations'],
                'sort_order' => 10,
            ],
        ]);
    }

    private function configureValidMessagingDefinition(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
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
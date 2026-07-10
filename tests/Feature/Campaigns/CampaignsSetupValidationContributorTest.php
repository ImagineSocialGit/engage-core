<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Campaigns\Validation\CampaignsSetupValidationContributor;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CampaignsSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('presets.modules.webinars.campaigns.groups', []);
        Config::set('presets.modules.webinars.campaigns.definitions', []);
        Config::set('messaging.email', []);
        Config::set('messaging.sms', []);

        $this->configureEmailAvailability();
    }

    public function test_it_accepts_valid_selected_campaign_preset(): void
    {
        $this->setPresetPackage(['general_default']);

        Config::set('presets.modules.webinars.campaigns.groups.general_default', [
            'test_campaign',
        ]);

        Config::set('presets.modules.webinars.campaigns.definitions.test_campaign', [
            'key' => 'test_campaign',
            'name' => 'Test Campaign',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Initial follow-up',
                    'variant_strategy' => 'first_available',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $this->findings());
    }

    public function test_it_warns_when_selected_campaign_has_orphaned_messaging_template_variant(): void
    {
        $this->setPresetPackage(['general_default']);

        Config::set('presets.modules.webinars.campaigns.groups.general_default', [
            'test_campaign',
        ]);

        Config::set('presets.modules.webinars.campaigns.definitions.test_campaign', [
            'key' => 'test_campaign',
            'name' => 'Test Campaign',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'steps' => [
                [
                    'step_number' => 1,
                    'name' => 'Initial follow-up',
                    'variant_strategy' => 'first_available',
                    'variants' => [
                        [
                            'key' => 'email',
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],
                ],
            ],
        ]);

        Config::set('messaging.email.marketing.webinar_nurture.campaigns.test_campaign.steps', [
            1 => [
                'variants' => [
                    'email' => [
                        'dispatch_key' => 'campaign_step_due',
                        'payload_class' => EmailPayload::class,
                        'queue' => 'marketing',
                        'payload' => [
                            'subject' => 'Step 1',
                            'body' => 'Expected template',
                        ],
                    ],
                ],
            ],
            2 => [
                'variants' => [
                    'email' => [
                        'dispatch_key' => 'campaign_step_due',
                        'payload_class' => EmailPayload::class,
                        'queue' => 'marketing',
                        'payload' => [
                            'subject' => 'Step 2',
                            'body' => 'Orphaned template',
                        ],
                    ],
                ],
            ],
        ]);

        $warnings = array_values(array_filter(
            $this->findings(),
            fn (array $finding): bool => $finding['code'] === 'campaigns.messaging_template_orphaned_from_selected_campaign',
        ));

        $this->assertCount(1, $warnings);
        $this->assertSame(SetupValidationFinding::SEVERITY_WARNING, $warnings[0]['severity']);
        $this->assertSame('test_campaign', data_get($warnings[0], 'context.campaign_key'));
        $this->assertSame(2, data_get($warnings[0], 'context.step_number'));
        $this->assertSame('email', data_get($warnings[0], 'context.variant_key'));
    }


    public function test_it_reports_invalid_strategy_duplicate_identity_and_reusable_copy(): void
    {
        $this->setPresetPackage(['general_default']);

        Config::set('presets.modules.webinars.campaigns.groups.general_default', [
            'invalid_campaign',
        ]);

        Config::set('presets.modules.webinars.campaigns.definitions.invalid_campaign', [
            'key' => 'invalid_campaign',
            'name' => 'Invalid Campaign',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'payload' => [
                'subject' => 'Wrong owner',
            ],
            'steps' => [
                [
                    'step_number' => 1,
                    'variant_strategy' => 'round_robin',
                    'payload' => [
                        'body' => 'Wrong owner',
                    ],
                    'variants' => [
                        [
                            'key' => 'email',
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                        [
                            'key' => 'email',
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],
                ],
                [
                    'step_number' => 1,
                    'variants' => [
                        [
                            'key' => 'sms',
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                    ],
                ],
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('campaigns.definition_invalid', $codes);
        $this->assertContains('campaigns.variant_strategy_invalid', $codes);
        $this->assertContains('campaigns.duplicate_step_number', $codes);
        $this->assertContains('campaigns.duplicate_variant_key', $codes);
        $this->assertContains('campaigns.reusable_copy_owned_by_campaign', $codes);
        $this->assertContains('campaigns.reusable_copy_owned_by_step', $codes);
    }

    public function test_it_reports_invalid_dependency_sibling_state_and_self_reference(): void
    {
        $this->setPresetPackage(['general_default']);

        Config::set('presets.modules.webinars.campaigns.groups.general_default', [
            'dependency_campaign',
        ]);

        Config::set('presets.modules.webinars.campaigns.definitions.dependency_campaign', [
            'key' => 'dependency_campaign',
            'name' => 'Dependency Campaign',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'steps' => [
                [
                    'step_number' => 1,
                    'variant_strategy' => 'dependency_aware',
                    'variants' => [
                        [
                            'key' => 'email',
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                        [
                            'key' => 'sms',
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dependency_rules' => [
                                'requires_variant_states' => [
                                    'sms' => ['scheduled'],
                                    'push' => ['teleported'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('campaigns.dependency_self_reference', $codes);
        $this->assertContains('campaigns.dependency_sibling_missing', $codes);
        $this->assertContains('campaigns.dependency_state_invalid', $codes);
    }

    public function test_it_warns_when_dependency_rules_are_dormant_under_non_dependency_strategy(): void
    {
        $this->setPresetPackage(['general_default']);

        Config::set('presets.modules.webinars.campaigns.groups.general_default', [
            'dormant_dependency_campaign',
        ]);

        Config::set('presets.modules.webinars.campaigns.definitions.dormant_dependency_campaign', [
            'key' => 'dormant_dependency_campaign',
            'name' => 'Dormant Dependency Campaign',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'steps' => [
                [
                    'step_number' => 1,
                    'variant_strategy' => 'send_all_eligible',
                    'variants' => [
                        [
                            'key' => 'email',
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'email',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                        ],
                        [
                            'key' => 'sms',
                            'dispatch_key' => 'campaign_step_due',
                            'channel' => 'sms',
                            'purpose' => 'marketing',
                            'scope' => 'webinar_nurture',
                            'dependency_rules' => [
                                'requires_variant_states' => [
                                    'email' => ['scheduled'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $warnings = array_values(array_filter(
            $this->findings(),
            fn (array $finding): bool => $finding['code'] === 'campaigns.dependency_rules_dormant',
        ));

        $this->assertCount(1, $warnings);
        $this->assertSame(
            SetupValidationFinding::SEVERITY_WARNING,
            $warnings[0]['severity'],
        );
    }

    public function test_it_reports_runtime_missing_messaging_definition_for_active_variant(): void
    {
        [$campaign, $step] = $this->runtimeCampaign();

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Email',
            'sort_order' => 0,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'meta' => [],
        ]);

        $this->assertContains(
            'campaigns.messaging_definition_missing',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_it_warns_when_runtime_channel_is_unavailable_without_reporting_missing_definition(): void
    {
        Config::set('messaging.channel_availability.email.surfaces.campaigns', false);

        [$campaign, $step] = $this->runtimeCampaign();

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Email',
            'sort_order' => 0,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'meta' => [],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('campaigns.channel_unavailable_for_surface', $codes);
        $this->assertNotContains('campaigns.messaging_definition_missing', $codes);
    }

    public function test_it_resolves_variant_aware_messaging_definition_for_runtime_campaign(): void
    {
        $this->configureCampaignMessagingDefinition();

        [$campaign, $step] = $this->runtimeCampaign();

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Email',
            'sort_order' => 0,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.runtime_campaign.steps.1.variants.email',
            'meta' => [],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertNotContains('campaigns.messaging_definition_missing', $codes);
        $this->assertNotContains('campaigns.messaging_definition_payload_unusable', $codes);
    }

    public function test_manager_resolves_tagged_campaigns_contributor(): void
    {
        [$campaign, $step] = $this->runtimeCampaign();

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Email',
            'sort_order' => 0,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'meta' => [],
        ]);

        $result = app(SetupValidationManager::class)->validate();

        $this->assertContains(
            'campaigns.messaging_definition_missing',
            array_map(
                fn (SetupValidationFinding $finding): string => $finding->code,
                $result->findings(),
            ),
        );
    }

    private function configureCampaignMessagingDefinition(): void
    {
        Config::set('messaging.email.marketing.webinar_nurture', [
            'campaigns' => [
                'runtime_campaign' => [
                    'steps' => [
                        1 => [
                            'variants' => [
                                'email' => [
                                    'dispatch_key' => 'campaign_step_due',
                                    'payload_class' => EmailPayload::class,
                                    'queue' => 'marketing',
                                    'payload' => [
                                        'subject' => 'Follow up',
                                        'body' => 'Thanks',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function configureEmailAvailability(): void
    {
        Config::set('messaging.channel_availability.email.runtime_supported', true);
        Config::set('messaging.channel_availability.email.provider_enabled', true);
        Config::set('messaging.channel_availability.email.surfaces.campaigns', true);
        Config::set('messaging.channel_availability.email.purpose_scopes', [
            'marketing:webinar_nurture' => true,
        ]);
    }

    /**
     * @return array{0: Campaign, 1: CampaignStep}
     */
    private function runtimeCampaign(): array
    {
        $campaign = Campaign::query()->create([
            'key' => 'runtime_campaign',
            'name' => 'Runtime Campaign',
            'description' => null,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => Campaign::STATUS_ACTIVE,
            'is_active' => true,
            'source_version' => '1',
            'is_customized' => false,
            'meta' => [],
        ]);

        $step = CampaignStep::query()->create([
            'campaign_id' => $campaign->id,
            'step_number' => 1,
            'name' => 'Initial follow-up',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'variant_strategy' => 'first_available',
            'is_active' => true,
            'criteria' => [
                'timing' => [
                    'type' => 'delay',
                    'minutes' => 30,
                ],
            ],
            'source_version' => '1',
            'is_customized' => false,
            'meta' => [
                'type' => 'message',
            ],
        ]);

        return [$campaign, $step];
    }

    /**
     * @param array<int, string> $groups
     */
    private function setPresetPackage(array $groups): void
    {
        Config::set('client.preset', 'campaigns_validation_test');

        Config::set('presets.packages.campaigns_validation_test', [
            'groups' => [
                'contact_statuses' => [],
                'tasks' => [],
                'flow_routes' => [],
                'campaigns' => $groups,
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findings(): array
    {
        return array_map(
            fn (SetupValidationFinding $finding): array => $finding->toArray(),
            iterator_to_array(
                app(CampaignsSetupValidationContributor::class)->findings(),
                false,
            ),
        );
    }
}

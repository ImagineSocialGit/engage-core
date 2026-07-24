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

    public function test_it_accepts_valid_selected_compact_campaign_preset(): void
    {
        $this->configureSelectedCampaign(
            campaignKey: 'test_campaign',
            definition: $this->compactCampaignDefinition(),
        );

        $this->assertSame([], $this->findings());
    }

    public function test_it_warns_when_selected_campaign_has_orphaned_messaging_template_variant(): void
    {
        $this->configureSelectedCampaign(
            campaignKey: 'test_campaign',
            definition: $this->compactCampaignDefinition(),
        );

        Config::set('messaging.email.definitions.marketing.webinar_nurture.campaigns.test_campaign.steps', [
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

    public function test_it_rejects_removed_verbose_campaign_authoring_fields(): void
    {
        $definition = $this->compactCampaignDefinition();
        $definition['key'] = 'test_campaign';

        $this->configureSelectedCampaign(
            campaignKey: 'test_campaign',
            definition: $definition,
        );

        $findings = $this->findings();

        $this->assertSame(['campaigns.definition_invalid'], array_column($findings, 'code'));
        $this->assertStringContainsString(
            'removed field [key]',
            $findings[0]['message'],
        );
    }

    public function test_it_reports_reusable_copy_owned_by_campaign_step_and_variant(): void
    {
        $definition = $this->compactCampaignDefinition();
        $definition['payload'] = ['subject' => 'Wrong owner'];
        $definition['steps'][0]['payload'] = ['body' => 'Wrong owner'];
        $definition['steps'][0]['variants']['email']['payload'] = ['body' => 'Wrong owner'];

        $this->configureSelectedCampaign(
            campaignKey: 'test_campaign',
            definition: $definition,
        );

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('campaigns.reusable_copy_owned_by_campaign', $codes);
        $this->assertContains('campaigns.reusable_copy_owned_by_step', $codes);
        $this->assertContains('campaigns.reusable_copy_owned_by_variant', $codes);
    }

    public function test_it_reports_dependency_self_reference_and_missing_sibling(): void
    {
        $definition = $this->compactCampaignDefinition();
        $definition['variant_strategy'] = 'dependency_aware';
        $definition['steps'][0]['variants']['sms'] = [
            'channel' => 'sms',
            'dependency_rules' => [
                'requires_variant_states' => [
                    'sms' => ['scheduled'],
                    'push' => ['unavailable'],
                ],
            ],
        ];

        $this->configureSelectedCampaign(
            campaignKey: 'dependency_campaign',
            definition: $definition,
        );

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('campaigns.dependency_self_reference', $codes);
        $this->assertContains('campaigns.dependency_sibling_missing', $codes);
    }

    public function test_it_rejects_unsupported_dependency_state_during_definition_parsing(): void
    {
        $definition = $this->compactCampaignDefinition();
        $definition['variant_strategy'] = 'dependency_aware';
        $definition['steps'][0]['variants']['sms'] = [
            'channel' => 'sms',
            'dependency_rules' => [
                'requires_variant_states' => [
                    'email' => ['teleported'],
                ],
            ],
        ];

        $this->configureSelectedCampaign(
            campaignKey: 'dependency_campaign',
            definition: $definition,
        );

        $findings = $this->findings();

        $this->assertSame(['campaigns.definition_invalid'], array_column($findings, 'code'));
        $this->assertStringContainsString(
            'unsupported campaign step variant dependency state',
            strtolower($findings[0]['message']),
        );
    }

    public function test_it_warns_when_dependency_rules_are_dormant_under_non_dependency_strategy(): void
    {
        $definition = $this->compactCampaignDefinition();
        $definition['variant_strategy'] = 'send_all_eligible';
        $definition['steps'][0]['variants']['sms'] = [
            'channel' => 'sms',
            'dependency_rules' => [
                'requires_variant_states' => [
                    'email' => ['scheduled'],
                ],
            ],
        ];

        $this->configureSelectedCampaign(
            campaignKey: 'dormant_dependency_campaign',
            definition: $definition,
        );

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
            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.runtime_campaign.steps.1.variants.email',
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

    /**
     * @param array<string, mixed> $definition
     */
    private function configureSelectedCampaign(
        string $campaignKey,
        array $definition,
    ): void {
        $this->setPresetPackage(['default']);

        Config::set('presets.modules.webinars.campaigns.groups.default', [
            $campaignKey,
        ]);
        Config::set(
            'presets.modules.webinars.campaigns.definitions.'.$campaignKey,
            $definition,
        );
    }

    /** @return array<string, mixed> */
    private function compactCampaignDefinition(): array
    {
        return [
            'name' => 'Test Campaign',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'steps' => [
                [
                    'name' => 'Initial follow-up',
                    'criteria' => [
                        'timing' => [
                            'type' => 'delay',
                            'hours' => 2,
                        ],
                    ],
                    'variants' => [
                        'email' => [
                            'channel' => 'email',
                        ],
                    ],
                ],
            ],
        ];
    }

    private function configureCampaignMessagingDefinition(): void
    {
        Config::set('messaging.email.definitions.marketing.webinar_nurture', [
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
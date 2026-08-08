<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageDefinitionModuleAvailability;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use App\Modules\Messaging\Validation\MessagingSetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MessageDefinitionModuleAvailabilityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('modules.enabled', ['messaging']);
        Config::set('messaging.email.definitions', []);
        Config::set('messaging.sms.definitions', []);
    }

    public function test_module_availability_preserves_campaign_ownership_without_creating_a_webinar_dependency(): void
    {
        $availability = app(MessageDefinitionModuleAvailability::class);

        $this->assertTrue($availability->standardDefinitionsAvailable('general'));
        $this->assertFalse($availability->standardDefinitionsAvailable('webinar'));
        $this->assertFalse($availability->standardDefinitionsAvailable('webinar_nurture'));
        $this->assertFalse($availability->campaignDefinitionsAvailable());

        Config::set('modules.enabled', [
            'messaging',
            'campaigns',
        ]);

        $this->assertTrue($availability->campaignDefinitionsAvailable());
        $this->assertFalse($availability->standardDefinitionsAvailable('webinar_nurture'));
        $this->assertTrue($availability->scopeContainsAvailableDefinitions(
            'webinar_nurture',
            ['campaigns' => []],
        ));
    }

    public function test_sync_omits_webinar_and_campaign_definitions_when_their_owning_modules_are_disabled(): void
    {
        Config::set('messaging.email.definitions', [
            'transactional' => [
                'webinar' => [
                    'default' => [
                        'confirmations' => [[
                            'key' => 'registration_confirmation',
                            'dispatch_key' => 'registration_created',
                            'payload_class' => EmailPayload::class,
                            'queue' => 'confirmation_messages',
                            'payload' => [
                                'subject' => '{webinar_title}',
                                'body' => 'Hi {first_name}.',
                            ],
                        ]],
                    ],
                ],
            ],
            'marketing' => [
                'webinar_nurture' => [
                    'campaigns' => [
                        'webinar_attended_nurture' => [
                            'steps' => [
                                1 => [
                                    'variants' => [
                                        'email' => [
                                            'dispatch_key' => 'campaign_step_due',
                                            'payload_class' => EmailPayload::class,
                                            'queue' => 'marketing',
                                            'payload' => [
                                                'subject' => '{webinar_title}',
                                                'body' => 'Follow up with {first_name}.',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $result = app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('message_template_presets', 0);
        $this->assertDatabaseCount('message_template_preset_assignments', 0);
        $this->assertDatabaseCount('message_template_catalog_entries', 0);
    }

    public function test_standard_webinar_assignment_and_config_fallback_are_runtime_inert_when_webinars_are_disabled(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => EmailPayload::class,
                'queue' => 'confirmation_messages',
                'payload' => [
                    'subject' => 'Config subject',
                    'body' => 'Config body.',
                ],
            ],
        ]);

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.transactional.webinar.confirmation',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => EmailPayload::class,
            'queue' => 'confirmation_messages',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => 'DB subject',
                'body' => 'DB body.',
            ],
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create();

        $definitions = app(MessageDefinitionResolver::class)->resolve(
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
        );

        $this->assertEquals([], $definitions);
    }

    public function test_campaign_assignment_and_config_fallback_are_runtime_inert_when_campaigns_are_disabled(): void
    {
        Config::set('messaging.email.definitions.marketing.webinar_nurture', [
            'campaigns' => [
                'webinar_attended_nurture' => [
                    'steps' => [
                        1 => [
                            'variants' => [
                                'email' => [
                                    'dispatch_key' => 'campaign_step_due',
                                    'payload_class' => EmailPayload::class,
                                    'queue' => 'marketing',
                                    'payload' => [
                                        'subject' => 'Config campaign subject',
                                        'body' => 'Config campaign body.',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'webinar_attended_nurture_step_1',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => 'DB campaign subject',
                'body' => 'DB campaign body.',
            ],
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStepVariant(
                campaignKey: 'webinar_attended_nurture',
                stepNumber: 1,
                variantKey: 'email',
                sourceConfigPath: null,
            )
            ->create();

        $definition = app(MessageDefinitionResolver::class)->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
            variantKey: 'email',
        );

        $this->assertNull($definition);
    }

    public function test_setup_validation_ignores_disabled_module_config_and_customized_dormant_templates(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'confirmation' => [
                'dispatch_key' => 'registration_created',
                'payload_class' => 'Missing\\WebinarPayload',
                'queue' => 'missing_webinar_queue',
                'payload' => [
                    'subject' => '{webinar_title}',
                    'body' => '{webinar_join_url}',
                ],
            ],
        ]);

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'custom.disabled.webinar',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'payload_class' => 'Missing\\CustomizedWebinarPayload',
            'queue' => 'missing_customized_webinar_queue',
            'dispatch_keys' => ['registration_created'],
            'payload' => [
                'subject' => '{webinar_title}',
                'body' => '{webinar_join_url}',
            ],
            'is_customized' => true,
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'webinars',
                'module_label' => 'Webinars',
            ]);

        $findings = array_map(
            fn (SetupValidationFinding $finding): array => $finding->toArray(),
            iterator_to_array(
                app(MessagingSetupValidationContributor::class)->findings(),
                false,
            ),
        );

        $disabledDefinitionFindings = array_values(array_filter(
            $findings,
            fn (array $finding): bool =>
                str_contains(
                    (string) ($finding['source'] ?? ''),
                    'messaging.email.definitions.transactional.webinar',
                )
                || ($finding['source'] ?? null) === 'message_template_presets',
        ));

        $this->assertEquals([], $disabledDefinitionFindings);
    }
}
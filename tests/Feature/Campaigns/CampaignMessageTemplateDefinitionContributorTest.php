<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CampaignMessageTemplateDefinitionContributorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('messaging.sms.definitions', []);
    }

    public function test_campaigns_materializes_campaign_templates_without_webinars_enabled(): void
    {
        Config::set('modules.enabled', [
            'messaging',
            'campaigns',
        ]);
        $this->configureCampaignTemplate('Client-owned campaign subject');

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $preset = MessageTemplatePreset::query()
            ->where('scope', 'webinar_nurture')
            ->where('message_type', 'webinar_attended_nurture_step_1')
            ->firstOrFail();

        $this->assertSame(
            'Client-owned campaign subject',
            data_get($preset->payload, 'subject'),
        );
        $this->assertDatabaseHas('message_template_catalog_entries', [
            'message_template_preset_id' => $preset->getKey(),
            'module_key' => 'campaigns',
            'surface' => 'campaigns',
            'usage_type' => 'campaign_step',
        ]);
    }

    public function test_campaigns_owns_campaign_steps_inside_messaging_owned_scope_without_duplicate_materialization(): void
    {
        Config::set('modules.enabled', [
            'messaging',
            'campaigns',
        ]);
        Config::set('messaging.email.definitions', [
            'marketing' => [
                'mortgage_homebuyer_nurture' => [
                    'campaigns' => [
                        'cold_lead_nurture' => [
                            'steps' => [
                                1 => [
                                    'variants' => [
                                        'email' => [
                                            'dispatch_key' => 'campaign_step_due',
                                            'payload_class' => EmailPayload::class,
                                            'queue' => 'marketing',
                                            'payload' => [
                                                'subject' => 'Mortgage nurture subject',
                                                'body' => 'Hi {first_name}.',
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

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $preset = MessageTemplatePreset::query()
            ->where('key', 'email.marketing.mortgage_homebuyer_nurture.campaigns.cold_lead_nurture.steps.1.variants.email')
            ->firstOrFail();

        $this->assertSame(
            'Mortgage nurture subject',
            data_get($preset->payload, 'subject'),
        );
        $this->assertSame(
            1,
            MessageTemplatePreset::query()
                ->where('key', $preset->key)
                ->count(),
        );
        $this->assertDatabaseHas('message_template_catalog_entries', [
            'message_template_preset_id' => $preset->getKey(),
            'module_key' => 'campaigns',
            'surface' => 'campaigns',
            'usage_type' => 'campaign_step',
        ]);
    }

    public function test_enabling_webinars_alongside_campaigns_does_not_duplicate_campaign_templates(): void
    {
        Config::set('modules.enabled', [
            'messaging',
            'campaigns',
            'webinars',
        ]);
        $this->configureCampaignTemplate('One campaign template');

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertSame(
            1,
            MessageTemplatePreset::query()
                ->where('scope', 'webinar_nurture')
                ->where('message_type', 'webinar_attended_nurture_step_1')
                ->count(),
        );
        $this->assertSame(
            1,
            MessageTemplateCatalogEntry::query()
                ->where('module_key', 'campaigns')
                ->where('usage_type', 'campaign_step')
                ->count(),
        );
    }

    private function configureCampaignTemplate(string $subject): void
    {
        Config::set('messaging.email.definitions', [
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
                                                'subject' => $subject,
                                                'body' => 'Hi {first_name}.',
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
    }
}
<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CampaignVariantPresetSyncSpecificityTest extends TestCase
{
    use RefreshDatabase;

    public function test_preset_sync_persists_variant_source_config_paths_and_removes_stale_non_customized_variants(): void
    {
        $this->configurePreset(['email', 'sms']);

        app(SyncCampaignPresetsAction::class)->handle(
            app(PresetCompositionResolver::class)->resolve(
                'test_client',
                PresetDomain::Campaigns,
            ),
        );

        $campaign = Campaign::query()->where('key', 'webinar_attended_nurture')->firstOrFail();
        $step = $campaign->steps()->firstOrFail();
        $emailVariant = $step->variants()->where('key', 'email')->firstOrFail();

        $this->assertSame('1', $campaign->source_version);
        $this->assertSame('1', $step->source_version);
        $this->assertSame('1', $emailVariant->source_version);

        $this->assertSame(
            'presets.modules.webinars.campaigns.definitions.webinar_attended_nurture.steps.1.variants.email',
            $emailVariant->source_config_path,
        );

        $this->configurePreset(['email']);

        app(SyncCampaignPresetsAction::class)->handle(
            app(PresetCompositionResolver::class)->resolve(
                'test_client',
                PresetDomain::Campaigns,
            ),
        );

        $this->assertDatabaseHas('campaign_step_variants', [
            'campaign_step_id' => $step->id,
            'key' => 'email',
        ]);

        $this->assertDatabaseMissing('campaign_step_variants', [
            'campaign_step_id' => $step->id,
            'key' => 'sms',
        ]);
    }

    public function test_preset_sync_preserves_customized_stale_variants(): void
    {
        $this->configurePreset(['email', 'sms']);

        app(SyncCampaignPresetsAction::class)->handle(
            app(PresetCompositionResolver::class)->resolve(
                'test_client',
                PresetDomain::Campaigns,
            ),
        );

        $campaign = Campaign::query()->where('key', 'webinar_attended_nurture')->firstOrFail();
        $step = $campaign->steps()->firstOrFail();
        $smsVariant = $step->variants()->where('key', 'sms')->firstOrFail();

        $smsVariant->forceFill([
            'is_customized' => true,
            'customized_at' => now(),
            'name' => 'Customized SMS variant',
        ])->save();

        $this->configurePreset(['email']);

        app(SyncCampaignPresetsAction::class)->handle(
            app(PresetCompositionResolver::class)->resolve(
                'test_client',
                PresetDomain::Campaigns,
            ),
        );

        $this->assertDatabaseHas('campaign_step_variants', [
            'id' => $smsVariant->id,
            'key' => 'sms',
            'name' => 'Customized SMS variant',
            'is_customized' => true,
        ]);
    }

    public function test_customized_campaign_does_not_receive_new_preset_steps_or_variants(): void
    {
        $campaign = Campaign::factory()->create([
            'key' => 'webinar_attended_nurture',
            'is_customized' => true,
            'customized_at' => now(),
        ]);

        $step = CampaignStep::factory()
            ->forCampaign($campaign)
            ->create([
                'step_number' => 1,
                'name' => 'Customized Step 1',
                'is_customized' => true,
                'customized_at' => now(),
            ]);

        CampaignStepVariant::factory()->create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Customized Email',
            'is_customized' => true,
            'customized_at' => now(),
        ]);

        $this->configurePreset(['email', 'sms'], includeSecondStep: true);

        app(SyncCampaignPresetsAction::class)->handle(
            app(PresetCompositionResolver::class)->resolve(
                'test_client',
                PresetDomain::Campaigns,
            ),
        );

        $this->assertDatabaseMissing('campaign_steps', [
            'campaign_id' => $campaign->id,
            'step_number' => 2,
        ]);

        $this->assertDatabaseMissing('campaign_step_variants', [
            'campaign_step_id' => $step->id,
            'key' => 'sms',
        ]);

        $this->assertDatabaseHas('campaign_step_variants', [
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Customized Email',
        ]);
    }

    /**
     * @param array<int, string> $variantKeys
     */
    private function configurePreset(array $variantKeys, bool $includeSecondStep = false): void
    {
        Config::set('presets.packages.test_client.groups.campaigns', ['webinar_default']);
        Config::set('presets.modules.webinars.campaigns.groups.webinar_default', ['webinar_attended_nurture']);

        $steps = [
            [
                'step_number' => 1,
                'name' => 'Initial follow-up',
                'dispatch_key' => 'campaign_step_due',
                'variant_strategy' => 'send_all_eligible',
                'is_active' => true,
                'source_version' => 1,
                'criteria' => ['timing' => ['type' => 'delay', 'minutes' => 30]],
                'meta' => ['type' => 'message'],
                'variants' => array_values(array_map(
                    fn (string $variantKey): array => [
                        'key' => $variantKey,
                        'name' => strtoupper($variantKey).' follow-up',
                        'sort_order' => $variantKey === 'email' ? 0 : 1,
                        'dispatch_key' => 'campaign_step_due',
                        'channel' => $variantKey === 'sms' ? 'sms' : 'email',
                        'purpose' => 'marketing',
                        'scope' => 'webinar_nurture',
                        'source_config_path' => 'presets.modules.webinars.campaigns.definitions.webinar_attended_nurture.steps.1.variants.'.$variantKey,
                    ],
                    $variantKeys,
                )),
            ],
        ];

        if ($includeSecondStep) {
            $steps[] = [
                'step_number' => 2,
                'name' => 'Second follow-up',
                'dispatch_key' => 'campaign_step_due',
                'variant_strategy' => 'first_available',
                'is_active' => true,
                'source_version' => 1,
                'criteria' => ['timing' => ['type' => 'delay', 'days' => 1]],
                'meta' => ['type' => 'message'],
                'variants' => [[
                    'key' => 'email',
                    'name' => 'Email follow-up',
                    'dispatch_key' => 'campaign_step_due',
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'webinar_nurture',
                    'source_config_path' => 'presets.modules.webinars.campaigns.definitions.webinar_attended_nurture.steps.2.variants.email',
                ]],
            ];
        }

        Config::set('presets.modules.webinars.campaigns.definitions.webinar_attended_nurture', [
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'description' => 'Follow-up sequence for webinar attendees.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'source_version' => 1,
            'steps' => $steps,
        ]);
    }
}

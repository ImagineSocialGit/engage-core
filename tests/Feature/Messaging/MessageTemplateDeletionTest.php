<?php

namespace Tests\Feature\Messaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Messaging\Actions\DeleteMessageTemplatePresetAction;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class MessageTemplateDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unused_template_can_be_deleted_from_library_without_deleting_version_history(): void
    {
        config()->set('modules.enabled', ['messaging']);

        $user = User::factory()->create();
        [$preset, $template, $version] = $this->templateFixture(
            source: 'crm_reusable',
            moduleKey: 'messaging',
            moduleLabel: 'Messaging',
        );

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->delete(route('crm.messaging.message-templates.destroy', $preset))
            ->assertRedirect(route('crm.messaging.message-templates.index'))
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('message_template_presets', [
            'id' => $preset->getKey(),
        ]);
        $this->assertDatabaseMissing('message_template_catalog_entries', [
            'message_template_preset_id' => $preset->getKey(),
        ]);
        $this->assertDatabaseHas('message_templates', [
            'id' => $template->getKey(),
            'status' => MessageTemplate::STATUS_ARCHIVED,
        ]);
        $this->assertDatabaseHas('message_template_versions', [
            'id' => $version->getKey(),
            'message_template_id' => $template->getKey(),
        ]);
    }

    public function test_enabled_module_assignment_blocks_deletion(): void
    {
        config()->set('modules.enabled', ['messaging', 'webinars']);

        [$preset] = $this->templateFixture(
            source: 'config',
            moduleKey: 'webinars',
            moduleLabel: 'Webinars',
        );

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'is_active' => true,
            ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be deleted while it is still in use');

        app(DeleteMessageTemplatePresetAction::class)->handle($preset);
    }

    public function test_disabled_module_config_assignment_is_treated_as_stale_materialization(): void
    {
        config()->set('modules.enabled', ['messaging']);

        [$preset, $template] = $this->templateFixture(
            source: 'config',
            moduleKey: 'webinars',
            moduleLabel: 'Webinars',
        );

        $assignment = MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'is_active' => true,
            ]);

        app(DeleteMessageTemplatePresetAction::class)->handle($preset);

        $this->assertDatabaseMissing('message_template_presets', [
            'id' => $preset->getKey(),
        ]);
        $this->assertDatabaseMissing('message_template_preset_assignments', [
            'id' => $assignment->getKey(),
        ]);
        $this->assertDatabaseHas('message_templates', [
            'id' => $template->getKey(),
            'status' => MessageTemplate::STATUS_ARCHIVED,
        ]);
    }

    public function test_disabled_module_does_not_make_client_authored_assignment_disposable(): void
    {
        config()->set('modules.enabled', ['messaging']);

        [$preset] = $this->templateFixture(
            source: 'crm_reusable',
            moduleKey: 'webinars',
            moduleLabel: 'Webinars',
        );

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'surface' => 'webinar_registrations',
                'message_type' => 'confirmation',
                'is_active' => true,
            ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be deleted while it is still in use');

        app(DeleteMessageTemplatePresetAction::class)->handle($preset);
    }

    public function test_campaign_config_assignment_without_materialized_campaign_does_not_block_deletion(): void
    {
        config()->set('modules.enabled', ['messaging', 'campaigns']);

        [$preset, $template] = $this->templateFixture(
            source: 'config',
            moduleKey: 'campaigns',
            moduleLabel: 'Campaigns',
        );
        $preset->forceFill([
            'scope' => 'webinar_nurture',
            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.'
                .'campaigns.webinar_attended_nurture.steps.1.variants.email',
        ])->save();

        $assignment = MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'surface' => 'campaigns',
                'campaign_key' => 'webinar_attended_nurture',
                'campaign_step' => 1,
                'campaign_step_variant_key' => 'email',
                'source_config_path' => $preset->source_config_path,
                'is_active' => true,
                'meta' => [
                    'source' => 'config_sync',
                    'source_config_path' => $preset->source_config_path,
                ],
            ]);

        app(DeleteMessageTemplatePresetAction::class)->handle($preset);

        $this->assertDatabaseMissing('message_template_presets', [
            'id' => $preset->getKey(),
        ]);
        $this->assertDatabaseMissing('message_template_preset_assignments', [
            'id' => $assignment->getKey(),
        ]);
        $this->assertDatabaseHas('message_templates', [
            'id' => $template->getKey(),
            'status' => MessageTemplate::STATUS_ARCHIVED,
        ]);
    }

    public function test_materialized_campaign_variant_blocks_deletion(): void
    {
        config()->set('modules.enabled', ['messaging', 'campaigns']);

        [$preset] = $this->templateFixture(
            source: 'config',
            moduleKey: 'campaigns',
            moduleLabel: 'Campaigns',
        );
        $preset->forceFill([
            'scope' => 'webinar_nurture',
            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.'
                .'campaigns.webinar_attended_nurture.steps.1.variants.email',
        ])->save();

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->create([
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'surface' => 'campaigns',
                'campaign_key' => 'webinar_attended_nurture',
                'campaign_step' => 1,
                'campaign_step_variant_key' => 'email',
                'source_config_path' => $preset->source_config_path,
                'is_active' => true,
                'meta' => [
                    'source' => 'config_sync',
                    'source_config_path' => $preset->source_config_path,
                ],
            ]);

        $campaign = Campaign::factory()->create([
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => Campaign::STATUS_INACTIVE,
        ]);
        $step = CampaignStep::factory()->create([
            'campaign_id' => $campaign->getKey(),
            'step_number' => 1,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
        ]);
        CampaignStepVariant::factory()->create([
            'campaign_step_id' => $step->getKey(),
            'key' => 'email',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
        ]);

        try {
            app(DeleteMessageTemplatePresetAction::class)->handle($preset);
            $this->fail('Deleting a template selected by a materialized Campaign should fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'Campaigns — Webinar Attended Nurture',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('message_template_presets', [
            'id' => $preset->getKey(),
        ]);
    }

    public function test_campaign_annual_touch_logical_reference_blocks_deletion(): void
    {
        config()->set('modules.enabled', ['messaging', 'campaigns']);

        [$preset] = $this->templateFixture(
            source: 'crm_reusable',
            moduleKey: 'campaigns',
            moduleLabel: 'Campaigns',
        );

        $campaign = Campaign::factory()->create();
        $now = now();

        $programId = DB::table('campaign_touch_programs')->insertGetId([
            'campaign_id' => $campaign->getKey(),
            'key' => 'deletion-guard-program',
            'name' => 'Deletion Guard Program',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $dateId = DB::table('campaign_touch_dates')->insertGetId([
            'campaign_touch_program_id' => $programId,
            'key' => 'birthday',
            'name' => 'Birthday',
            'source_type' => 'birthday',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('campaign_touch_variants')->insert([
            'campaign_touch_date_id' => $dateId,
            'key' => 'email',
            'name' => 'Email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'annual_touch',
            'message_template_preset_id' => $preset->getKey(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            app(DeleteMessageTemplatePresetAction::class)->handle($preset);
            $this->fail('Deleting a template referenced by an annual-touch variant should fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Annual touch programs', $exception->getMessage());
        }

        $this->assertDatabaseHas('message_template_presets', [
            'id' => $preset->getKey(),
        ]);
    }

    /**
     * @return array{0: MessageTemplatePreset, 1: MessageTemplate, 2: MessageTemplateVersion}
     */
    private function templateFixture(
        string $source,
        string $moduleKey,
        string $moduleLabel,
    ): array {
        $key = 'email.transactional.deletion.'.Str::lower((string) Str::uuid());

        $preset = MessageTemplatePreset::factory()->create([
            'key' => $key,
            'name' => 'Deletion Fixture',
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'deletion_fixture',
            'message_type' => 'deletion_fixture',
            'payload_class' => EmailPayload::class,
            'queue' => 'notifications',
            'dispatch_keys' => ['deletion_fixture'],
            'payload' => [
                'subject' => 'Deletion fixture',
                'body' => 'Deletion fixture body.',
            ],
            'source' => $source,
            'is_customized' => $source !== 'config',
            'customized_at' => $source !== 'config' ? now() : null,
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'channel' => 'email',
                'purpose' => 'transactional',
                'scope' => 'deletion_fixture',
                'module_key' => $moduleKey,
                'module_label' => $moduleLabel,
                'surface' => 'message_templates',
                'group_key' => $moduleKey.':deletion',
                'group_label' => $moduleLabel.' Deletion',
                'item_key' => $key,
                'item_label' => 'Deletion Fixture',
                'usage_type' => 'deletion_fixture',
                'source' => $source,
                'is_active' => true,
            ]);

        $template = MessageTemplate::query()->create([
            'key' => $key,
            'name' => 'Deletion Fixture',
            'description' => null,
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => $source,
            'source_version' => 1,
            'is_customized' => $source !== 'config',
            'customized_at' => $source !== 'config' ? now() : null,
        ]);

        $version = app(PublishMessageTemplateVersionAction::class)->handle(
            messageTemplate: $template,
            payload: [
                'subject' => 'Deletion fixture',
                'body' => 'Deletion fixture body.',
            ],
        );

        return [$preset, $template->refresh(), $version];
    }
}
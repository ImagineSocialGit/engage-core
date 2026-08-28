<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageChainExecutionContextResolver;
use App\Modules\Messaging\Services\MessageTemplatePublicationHookRegistry;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\MessagingAppointmentCommunications;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentMessageChainExecutionContextProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentTokenContextProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentTokenSourceProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentTemplatePublicationHook;
use App\Support\TokenContracts\TokenContractRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AppointmentCommunicationsAuthoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->enableSchedulingMessagingIntegration();
        $this->configureChannels();
    }

    public function test_generate_schedule_publishes_confirmation_and_three_default_reminders(): void
    {
        $plan = app(MessagingAppointmentCommunications::class)
            ->generateDefaultSchedule();

        $this->assertTrue($plan['configured']);
        $this->assertCount(4, $plan['steps']);
        $this->assertEquals(
            ['confirmation', 'reminder_3_days', 'reminder_24_hours', 'reminder_1_hour'],
            array_column($plan['steps'], 'key'),
        );
        $this->assertEquals(
            ['immediate', 'before', 'before', 'before'],
            array_column($plan['steps'], 'timing'),
        );
        $this->assertEquals(
            [null, 3, 24, 1],
            array_column($plan['steps'], 'offset_value'),
        );
        $this->assertEquals(
            [null, 'days', 'hours', 'hours'],
            array_column($plan['steps'], 'offset_unit'),
        );

        $chain = MessageChain::query()
            ->where('key', 'scheduling_appointment_communications')
            ->sole();

        $this->assertTrue($chain->isActive());
        $this->assertNotNull($chain->current_version_id);
        $this->assertSame(1, $chain->versions()->count());
        $this->assertSame(8, MessageTemplate::query()->count());
        $this->assertSame(8, MessageTemplatePreset::query()->count());
        $this->assertSame(8, MessageTemplateCatalogEntry::query()->active()->count());
        $this->assertSame(8, MessageTemplateCatalogEntry::query()
            ->active()
            ->where('module_key', 'scheduling')
            ->where('group_key', 'scheduling:appointment_communications')
            ->where('usage_type', 'scheduling_appointment_communication')
            ->count());

        $steps = $chain->requireCurrentVersion()->steps()->orderBy('sort_order')->get();

        $this->assertSame(MessageChainStep::TIMING_IMMEDIATE, $steps[0]->timing_type);
        $this->assertSame(MessageChainStep::TIMING_ANCHORED, $steps[1]->timing_type);
        $this->assertSame('appointment.starts_at', $steps[1]->anchor_key);
        $this->assertSame(-259200, (int) $steps[1]->offset_seconds);
        $this->assertSame(-86400, (int) $steps[2]->offset_seconds);
        $this->assertSame(-3600, (int) $steps[3]->offset_seconds);
    }

    public function test_saved_changes_publish_a_new_chain_version_without_rewriting_history(): void
    {
        $communications = app(MessagingAppointmentCommunications::class);
        $initial = $communications->generateDefaultSchedule();
        $chain = MessageChain::query()
            ->where('key', 'scheduling_appointment_communications')
            ->sole();
        $initialVersionId = (int) $chain->current_version_id;

        $steps = $initial['steps'];
        $steps[1]['offset_value'] = 2;
        $steps[1]['offset_unit'] = 'days';
        $steps[1]['message'] = 'Updated appointment reminder for {first_name} on {appointment_date}.';

        $updated = $communications->saveSchedule($steps);
        $chain->refresh();

        $this->assertTrue($updated['configured']);
        $this->assertSame(2, $chain->versions()->count());
        $this->assertNotSame($initialVersionId, (int) $chain->current_version_id);
        $this->assertDatabaseHas('message_chain_versions', [
            'id' => $initialVersionId,
            'message_chain_id' => $chain->getKey(),
        ]);
        $this->assertSame(2, $updated['steps'][1]['offset_value']);
        $this->assertSame('days', $updated['steps'][1]['offset_unit']);
    }

    public function test_sync_catalog_command_backfills_an_existing_pre_catalog_schedule(): void
    {
        app(MessagingAppointmentCommunications::class)->generateDefaultSchedule();

        MessageTemplateCatalogEntry::query()->delete();
        MessageTemplatePreset::query()->delete();

        $this->assertSame(0, MessageTemplateCatalogEntry::query()->count());
        $this->assertSame(0, MessageTemplatePreset::query()->count());

        $this->artisan('scheduling:communications:sync-catalog')
            ->assertSuccessful();

        $this->assertSame(8, MessageTemplatePreset::query()->count());
        $this->assertSame(8, MessageTemplateCatalogEntry::query()->active()->count());
    }

    public function test_removed_channels_are_deactivated_in_the_message_template_catalog(): void
    {
        $communications = app(MessagingAppointmentCommunications::class);
        $plan = $communications->generateDefaultSchedule();
        $plan['steps'][1]['channels'] = ['email'];

        $communications->saveSchedule($plan['steps']);

        $this->assertDatabaseHas('message_template_catalog_entries', [
            'item_key' => 'scheduling_appointment_communications_reminder_3_days_email',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('message_template_catalog_entries', [
            'item_key' => 'scheduling_appointment_communications_reminder_3_days_sms',
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('message_template_presets', [
            'key' => 'scheduling_appointment_communications_reminder_3_days_sms',
            'status' => MessageTemplatePreset::STATUS_INACTIVE,
            'is_active' => false,
        ]);
    }

    public function test_message_template_library_edit_republishes_the_chain_for_future_appointments(): void
    {
        $communications = app(MessagingAppointmentCommunications::class);
        $communications->generateDefaultSchedule();

        $chain = MessageChain::query()
            ->where('key', 'scheduling_appointment_communications')
            ->sole();
        $originalChainVersionId = (int) $chain->current_version_id;
        $preset = MessageTemplatePreset::query()
            ->where('key', 'scheduling_appointment_communications_reminder_24_hours_email')
            ->sole();
        $originalTemplateVersionId = (int) $preset->canonicalTemplate
            ->current_version_id;
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.messaging.message-templates.index', [
                'module' => 'scheduling',
                'preset' => $preset->getKey(),
            ]))
            ->assertOk()
            ->assertViewHas('selectedCatalogEntry', fn ($entry): bool =>
                $entry instanceof MessageTemplateCatalogEntry
                && $entry->group_key === 'scheduling:appointment_communications'
            );

        $this->actingAs($user)
            ->patch(route('crm.messaging.message-templates.update', $preset), [
                'payload' => [
                    'subject' => 'Updated 24-hour reminder',
                    'body' => 'Updated library copy for {first_name} on {appointment_date}.',
                ],
            ])
            ->assertRedirect();

        $preset->refresh()->load('canonicalTemplate.currentVersion');
        $chain->refresh();

        $this->assertNotSame(
            $originalTemplateVersionId,
            (int) $preset->canonicalTemplate->current_version_id,
        );
        $this->assertNotSame(
            $originalChainVersionId,
            (int) $chain->current_version_id,
        );

        $currentStep = $chain->requireCurrentVersion()
            ->steps()
            ->where('key', 'reminder_24_hours')
            ->firstOrFail();
        $currentVariant = $currentStep->variants()
            ->where('channel', 'email')
            ->sole();

        $this->assertSame(
            (int) $preset->canonicalTemplate->current_version_id,
            (int) $currentVariant->message_template_version_id,
        );

        $originalStep = $chain->versions()
            ->whereKey($originalChainVersionId)
            ->firstOrFail()
            ->steps()
            ->where('key', 'reminder_24_hours')
            ->firstOrFail();
        $originalVariant = $originalStep->variants()
            ->where('channel', 'email')
            ->sole();

        $this->assertSame(
            $originalTemplateVersionId,
            (int) $originalVariant->message_template_version_id,
        );
    }

    public function test_workspace_generates_and_edits_schedule_without_exposing_message_chain_language(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.communications.index'))
            ->assertOk()
            ->assertSee('data-appointment-communications-empty', false)
            ->assertDontSee('MessageChain');

        $this->actingAs($user)
            ->post(route('crm.scheduling.configuration.communications.generate'))
            ->assertRedirect(route('crm.scheduling.configuration.communications.index'));

        $this->actingAs($user)
            ->get(route('crm.scheduling.configuration.communications.index'))
            ->assertOk()
            ->assertSee('data-appointment-communications-editor', false)
            ->assertSee('x-bind:name="`steps[${index}][message]`"', false)
            ->assertDontSee('MessageChain');
    }

    private function enableSchedulingMessagingIntegration(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
            'messaging',
        ])));

        if (! $this->app->getProvider(SchedulingModuleServiceProvider::class)) {
            $this->app->register(SchedulingModuleServiceProvider::class);
        }

        $this->app->tag(
            SchedulingAppointmentTokenSourceProvider::class,
            'token.source_providers',
        );
        $this->app->tag(
            SchedulingAppointmentTokenContextProvider::class,
            'token.context_providers',
        );
        $this->app->tag(
            SchedulingAppointmentMessageChainExecutionContextProvider::class,
            'messaging.message_chain_execution_context_providers',
        );
        $this->app->tag(
            SchedulingAppointmentTemplatePublicationHook::class,
            'messaging.message_template_publication_hooks',
        );

        $this->app->forgetInstance(TokenContractRegistry::class);
        $this->app->forgetInstance(MessageTemplatePublicationHookRegistry::class);
        $this->app->forgetInstance(MessageTemplateTokenValidator::class);
        $this->app->forgetInstance(MessageChainExecutionContextResolver::class);
    }

    private function configureChannels(): void
    {
        foreach (['email', 'sms'] as $channel) {
            config()->set("messaging.channel_availability.{$channel}.runtime_supported", true);
            config()->set("messaging.channel_availability.{$channel}.provider_enabled", true);
            config()->set("messaging.channel_availability.{$channel}.surfaces.scheduling_appointments", true);
            config()->set("messaging.channel_availability.{$channel}.purpose_scopes", ['*' => true]);
        }
    }
}
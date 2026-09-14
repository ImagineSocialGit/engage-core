<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageDefinitionModuleAvailability;
use App\Modules\Messaging\Services\MessageTemplateDefinitionRegistry;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentTokenContextProvider;
use App\Support\ModuleIntegrations\Scheduling\Messaging\SchedulingAppointmentTokenSourceProvider;
use App\Support\TokenContracts\TokenContractRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ModuleOwnedMessageTemplateSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_webinars_do_not_materialize_webinar_message_templates(): void
    {
        Config::set('modules.enabled', ['messaging']);
        Config::set(
            'messaging.email.definitions.transactional.webinar.default.confirmations.0.payload.subject',
            'Client webinar confirmation',
        );

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertDatabaseMissing('message_template_presets', [
            'scope' => 'webinar',
        ]);
        $this->assertDatabaseMissing('message_template_catalog_entries', [
            'module_key' => 'webinars',
        ]);
    }

    public function test_disabling_webinars_removes_non_customized_webinar_templates_on_the_next_sync(): void
    {
        Config::set('modules.enabled', ['messaging', 'webinars']);

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertDatabaseHas('message_template_presets', [
            'key' => 'email.transactional.webinar.confirmation',
            'source' => 'config',
            'is_customized' => false,
        ]);

        Config::set('modules.enabled', ['messaging']);

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $this->assertDatabaseMissing('message_template_presets', [
            'key' => 'email.transactional.webinar.confirmation',
        ]);
        $this->assertDatabaseHas('message_templates', [
            'key' => 'email.transactional.webinar.confirmation',
            'status' => 'archived',
        ]);
    }

    public function test_enabled_webinars_materialize_client_overrides_through_the_webinar_contributor(): void
    {
        Config::set('modules.enabled', ['messaging', 'webinars']);
        Config::set(
            'messaging.email.definitions.transactional.webinar.default.confirmations.0.payload.subject',
            'Client webinar confirmation',
        );

        app(SyncMessageTemplatePresetsAction::class)->handle();

        $preset = MessageTemplatePreset::query()
            ->where('key', 'email.transactional.webinar.confirmation')
            ->firstOrFail();

        $this->assertSame(
            'Client webinar confirmation',
            $preset->payload['subject'] ?? null,
        );
        $this->assertDatabaseHas('message_template_catalog_entries', [
            'message_template_preset_id' => $preset->getKey(),
            'module_key' => 'webinars',
            'module_label' => 'Webinars',
            'surface' => 'webinar_registrations',
        ]);
    }

    public function test_scheduling_and_messaging_materialize_scheduling_owned_standard_templates(): void
    {
        $this->enableSchedulingMessagingIntegration();
        Config::set('messaging.email.definitions', []);
        Config::set('messaging.sms.definitions', []);
        Config::set(
            'scheduling.communications.default_steps.0.message',
            'Custom appointment confirmation for {first_name}.',
        );

        app(SyncMessageTemplatePresetsAction::class)->handle();

        foreach (['email', 'sms'] as $channel) {
            foreach ([
                'confirmation',
                'reminder_3_days',
                'reminder_24_hours',
                'reminder_1_hour',
            ] as $stepKey) {
                $this->assertDatabaseHas('message_template_presets', [
                    'key' => "scheduling_appointment_communications_{$stepKey}_{$channel}",
                    'scope' => 'scheduling_appointments',
                    'source' => 'config',
                ]);
            }
        }

        $confirmation = MessageTemplatePreset::query()
            ->where('key', 'scheduling_appointment_communications_confirmation_email')
            ->firstOrFail();

        $this->assertSame(
            'Custom appointment confirmation for {first_name}.',
            $confirmation->payload['body'] ?? null,
        );
        $this->assertSame(
            8,
            MessageTemplatePreset::query()
                ->where('scope', 'scheduling_appointments')
                ->count(),
        );
        $this->assertSame(
            8,
            $this->app['db']->table('message_template_catalog_entries')
                ->where('module_key', 'scheduling')
                ->count(),
        );
        $this->assertSame(
            8,
            $this->app['db']->table('message_template_catalog_entries')
                ->where('module_key', 'scheduling')
                ->where('group_key', 'scheduling:appointment_communications')
                ->where('group_label', 'Appointment Communications')
                ->where('usage_type', 'scheduling_appointment_communication')
                ->count(),
        );
    }

    public function test_scheduling_owned_scope_is_known_even_when_scheduling_is_disabled(): void
    {
        Config::set('modules.enabled', ['messaging']);

        $registry = app(MessageTemplateDefinitionRegistry::class);

        $this->assertSame(
            'scheduling',
            $registry->ownerModuleForScope('scheduling_appointments'),
        );
        $this->assertFalse($registry->scopeOwnerEnabled('scheduling_appointments'));
        $this->assertSame('webinars', $registry->ownerModuleForScope('webinar'));
        $this->assertFalse($registry->scopeOwnerEnabled('webinar'));
    }

    private function enableSchedulingMessagingIntegration(): void
    {
        Config::set('modules.enabled', ['messaging', 'scheduling']);

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

        $this->app->forgetInstance(MessageTemplateDefinitionRegistry::class);
        $this->app->forgetInstance(MessageDefinitionModuleAvailability::class);
        $this->app->forgetInstance(TokenContractRegistry::class);
        $this->app->forgetInstance(MessageTemplateTokenValidator::class);
    }
}
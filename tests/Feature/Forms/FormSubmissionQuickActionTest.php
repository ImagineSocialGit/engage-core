<?php

namespace Tests\Feature\Forms;

use App\Modules\FlowRoutes\Actions\SyncFlowRouteCapabilitiesAction;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Services\FlowRoutePointAuthoringService;
use App\Modules\Forms\Data\HostedFormContextReference;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Modules\Tasks\Models\TaskTemplate;
use App\Modules\Tasks\Providers\TasksModuleServiceProvider;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Providers\CampaignsModuleServiceProvider;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Providers\MessagingModuleServiceProvider;
use App\Support\AutomationCapabilities\AutomationActionRegistry;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\AutomationPointDefinitionRegistry;
use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\ModuleIntegrations\Forms\FlowRoutes\QuickFormSubmissionAutomationService;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use App\Support\ModuleIntegrations\Forms\Contracts\FormSubmissionAutomationWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FormSubmissionQuickActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', ['forms', 'workflow', 'flow_routes']);
        config()->set('forms.external_intake', [
            'enabled' => true,
            'max_body_bytes' => 262144,
            'max_timestamp_drift_seconds' => 300,
            'nonce_ttl_seconds' => 600,
            'unauthenticated_rate_limit_per_minute' => 120,
            'client_rate_limit_per_minute' => 60,
            'clients' => [
                'engage_sites' => [
                    'secret' => 'test-external-forms-secret-with-more-than-32-bytes',
                    'source' => 'engage_sites',
                    'provider' => 'engage_sites',
                    'allowed_forms' => ['event_signup'],
                    'domains' => ['example.com'],
                ],
            ],
        ]);
        $this->app->register(FormsModuleServiceProvider::class, true);
        $this->app->forgetInstance(AutomationTriggerAuthoringRegistry::class);
        $this->app->forgetInstance(FormSubmissionAutomationWorkspace::class);

        $form = FormDefinition::factory()->active()->public()->create([
            'key' => 'event_signup',
            'name' => 'Event signup',
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $form->getKey(),
            'version' => 1,
            'name' => 'Event signup',
            'schema' => [
                'sections' => [[
                    'key' => 'contact',
                    'label' => 'Contact',
                    'fields' => [[
                        'key' => 'email',
                        'label' => 'Email',
                        'type' => 'email',
                        'required' => true,
                    ]],
                ]],
            ],
            'settings' => [
                'submission' => [
                    'contact' => [
                        'fields' => ['email' => 'email'],
                    ],
                ],
            ],
        ]);
        $form->forceFill(['current_form_version_id' => $version->getKey()])->save();
    }

    public function test_inline_tag_creates_one_enabled_event_scoped_behavior(): void
    {
        $result = app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $this->assertSame([], $result['errors']);

        $workspace = app(FormSubmissionAutomationWorkspace::class);
        $nashville = new HostedFormContextReference('event', 42);
        $presentation = $workspace->readForForm('event_signup', 'Event signup', true, $nashville, 'Nashville');
        $action = collect($presentation['actions'])->firstWhere('key', 'core.add_contact_tag');

        $this->assertIsArray($action);
        $this->assertNotEmpty($action['inline_fields']);

        $id = $workspace->createQuickActionForForm(
            formKey: 'event_signup',
            formName: 'Event signup',
            contactAvailable: true,
            capabilityKey: 'core.add_contact_tag',
            input: ['tag' => 'event:nashville'],
            context: $nashville,
            contextLabel: 'Nashville',
        );

        $route = FlowRoute::query()->findOrFail($id);
        $conditions = data_get($route->meta, 'definition.entry_conditions');
        $this->assertSame(FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR, $route->authoringKind());
        $this->assertTrue((bool) $route->is_active);
        $this->assertSame('event_signup', $conditions[0]['value']);
        $this->assertSame('event', $conditions[1]['value']);
        $this->assertSame('42', $conditions[2]['value']);
        $this->assertSame('event:nashville', $route->activeFlowRoutePoints()->sole()->definition['tag']);
        $this->assertTrue($route->activeTriggerBindings()->exists());

        $other = $workspace->readForForm(
            'event_signup', 'Event signup', true,
            new HostedFormContextReference('event', 43), 'Atlanta',
        );
        $this->assertSame([], collect($other['automations'])->pluck('id')->all());
    }

    public function test_missing_contact_mapping_never_creates_an_automation(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $workspace = app(FormSubmissionAutomationWorkspace::class);

        try {
            $workspace->createQuickActionForForm(
                formKey: 'event_signup',
                formName: 'Event signup',
                contactAvailable: false,
                capabilityKey: 'core.add_contact_tag',
                input: ['tag' => 'not-allowed'],
                context: new HostedFormContextReference('event', 42),
            );
            $this->fail('Expected quick authoring to reject a form without contact mapping.');
        } catch (ValidationException) {
            $this->assertSame(0, FlowRoute::query()->count());
        }
    }

    public function test_unapproved_or_invalid_quick_action_is_rejected_without_writes(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $workspace = app(FormSubmissionAutomationWorkspace::class);

        foreach ([
            ['flow_routes.custom', ['tag' => 'example']],
            ['core.add_contact_tag', ['tag' => '']],
        ] as [$capability, $input]) {
            try {
                $workspace->createQuickActionForForm(
                    formKey: 'event_signup',
                    formName: 'Event signup',
                    contactAvailable: true,
                    capabilityKey: $capability,
                    input: $input,
                    context: new HostedFormContextReference('event', 42),
                );
                $this->fail('Expected invalid inline authoring input to be rejected.');
            } catch (ValidationException) {
                $this->assertSame(0, FlowRoute::query()->count());
            }
        }
    }

    public function test_remove_tag_is_an_inline_contextual_behavior(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $workspace = app(FormSubmissionAutomationWorkspace::class);
        $context = new HostedFormContextReference('event', 42);

        $action = collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['actions'])
            ->firstWhere('key', 'core.remove_contact_tag');
        $this->assertIsArray($action);
        $this->assertNotEmpty($action['inline_fields']);

        $id = $workspace->createQuickActionForForm(
            formKey: 'event_signup',
            formName: 'Event signup',
            contactAvailable: true,
            capabilityKey: 'core.remove_contact_tag',
            input: ['tag' => 'temporary-lead'],
            context: $context,
        );

        $route = FlowRoute::query()->findOrFail($id);
        $this->assertTrue((bool) $route->is_active);
        $point = $route->activeFlowRoutePoints()->sole();
        $this->assertSame('remove_contact_tag', $point->type);
        $this->assertSame('temporary-lead', $point->definition['tag']);
    }

    public function test_event_quick_action_can_be_turned_off_and_on_without_losing_its_definition(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $workspace = app(FormSubmissionAutomationWorkspace::class);
        $context = new HostedFormContextReference('event', 42);
        $id = $workspace->createQuickActionForForm(
            formKey: 'event_signup',
            formName: 'Event signup',
            contactAvailable: true,
            capabilityKey: 'core.add_contact_tag',
            input: ['tag' => 'event:test'],
            context: $context,
        );

        $this->assertSame(
            'forms.after_submission',
            data_get(FlowRoute::query()->findOrFail($id)->meta, 'authoring.source'),
        );

        $before = collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['automations'])
            ->firstWhere('id', $id);
        $this->assertTrue($before['quick_manageable']);
        $this->assertTrue($before['is_enabled']);

        $workspace->setQuickActionEnabledForForm('event_signup', true, $id, false, $context);
        $route = FlowRoute::query()->findOrFail($id);
        $this->assertFalse((bool) $route->is_active);
        $this->assertFalse($route->activeTriggerBindings()->exists());
        $this->assertSame('event:test', $route->activeFlowRoutePoints()->sole()->definition['tag']);

        $after = collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['automations'])
            ->firstWhere('id', $id);
        $this->assertTrue($after['quick_manageable']);
        $this->assertFalse($after['is_enabled']);

        $workspace->setQuickActionEnabledForForm('event_signup', true, $id, true, $context);
        $route->refresh();
        $this->assertTrue((bool) $route->is_active);
        $this->assertTrue($route->activeTriggerBindings()->exists());
    }

    public function test_quick_management_rejects_a_different_event_or_form(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $workspace = app(FormSubmissionAutomationWorkspace::class);
        $context = new HostedFormContextReference('event', 42);
        $id = $workspace->createQuickActionForForm(
            formKey: 'event_signup',
            formName: 'Event signup',
            contactAvailable: true,
            capabilityKey: 'core.add_contact_tag',
            input: ['tag' => 'event:test'],
            context: $context,
        );

        foreach ([
            ['event_signup', new HostedFormContextReference('event', 43)],
            ['wrong_form', $context],
            ['event_signup', null],
        ] as [$formKey, $wrongContext]) {
            try {
                $workspace->setQuickActionEnabledForForm($formKey, true, $id, false, $wrongContext);
                $this->fail('Expected a foreign action management request to be rejected.');
            } catch (ValidationException) {
                $this->assertTrue((bool) FlowRoute::query()->findOrFail($id)->is_active);
            }
        }
    }

    public function test_quick_management_does_not_expose_advanced_or_modified_routes(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $workspace = app(FormSubmissionAutomationWorkspace::class);
        $context = new HostedFormContextReference('event', 42);
        $id = $workspace->createQuickActionForForm(
            formKey: 'event_signup',
            formName: 'Event signup',
            contactAvailable: true,
            capabilityKey: 'core.add_contact_tag',
            input: ['tag' => 'event:test'],
            context: $context,
        );
        $route = FlowRoute::query()->findOrFail($id);
        $meta = $route->meta;
        $meta['definition']['entry_conditions'][] = [
            'source' => 'execution_meta',
            'path' => 'automation_event.payload.unknown',
            'operator' => 'equals',
            'value' => 'extra',
        ];
        $route->forceFill(['meta' => $meta])->save();

        $presentation = collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['automations'])
            ->firstWhere('id', $id);
        $this->assertFalse($presentation['quick_manageable']);

        try {
            $workspace->setQuickActionEnabledForForm('event_signup', true, $id, false, $context);
            $this->fail('Expected an edited route to require Advanced automation.');
        } catch (ValidationException) {
            $this->assertTrue((bool) $route->fresh()->is_active);
        }
    }

    public function test_editing_a_quick_action_in_advanced_revokes_inline_management(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $workspace = app(FormSubmissionAutomationWorkspace::class);
        $context = new HostedFormContextReference('event', 42);
        $id = $workspace->createQuickActionForForm(
            formKey: 'event_signup',
            formName: 'Event signup',
            contactAvailable: true,
            capabilityKey: 'core.add_contact_tag',
            input: ['tag' => 'original'],
            context: $context,
        );

        $route = FlowRoute::query()->findOrFail($id);
        $this->assertSame('forms.after_submission', data_get($route->meta, 'authoring.source'));

        app(FlowRoutePointAuthoringService::class)->update(
            $route,
            $route->activeFlowRoutePoints()->sole(),
            ['tag' => 'edited-in-advanced'],
        );

        $route->refresh();
        $this->assertSame('crm', data_get($route->meta, 'authoring.source'));
        $this->assertSame('edited-in-advanced', $route->activeFlowRoutePoints()->sole()->definition['tag']);

        $automation = collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['automations'])
            ->firstWhere('id', $id);
        $this->assertFalse($automation['quick_manageable']);

        try {
            $workspace->setQuickActionEnabledForForm('event_signup', true, $id, false, $context);
            $this->fail('Expected an Advanced-edited Route to reject quick management.');
        } catch (ValidationException) {
            $this->assertTrue((bool) $route->fresh()->is_active);
        }
    }

    public function test_missing_contact_mapping_blocks_reenabling_but_allows_turning_off(): void
    {
        app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $workspace = app(FormSubmissionAutomationWorkspace::class);
        $context = new HostedFormContextReference('event', 42);
        $id = $workspace->createQuickActionForForm(
            formKey: 'event_signup',
            formName: 'Event signup',
            contactAvailable: true,
            capabilityKey: 'core.add_contact_tag',
            input: ['tag' => 'event:test'],
            context: $context,
        );
        $workspace->setQuickActionEnabledForForm('event_signup', false, $id, false, $context);
        $this->assertFalse((bool) FlowRoute::query()->findOrFail($id)->is_active);

        try {
            $workspace->setQuickActionEnabledForForm('event_signup', false, $id, true, $context);
            $this->fail('Expected reactivation without contact mapping to fail.');
        } catch (ValidationException) {
            $this->assertFalse((bool) FlowRoute::query()->findOrFail($id)->is_active);
        }
    }


    public function test_event_follow_up_task_uses_an_active_template_and_existing_route_runtime(): void
    {
        $this->registerAfterSignupActionModules();
        $template = TaskTemplate::factory()->currentContactSubject()->create([
            'key' => 'event_attendee_follow_up',
        ]);
        $result = app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $this->assertSame([], $result['errors']);
        $workspace = app(FormSubmissionAutomationWorkspace::class);
        $context = new HostedFormContextReference('event', 42);
        $action = collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['actions'])
            ->firstWhere('key', 'tasks.create_task');

        $this->assertIsArray($action);
        $this->assertSame(['task_template_key'], array_column($action['inline_fields'], 'name'));

        $id = $workspace->createQuickActionForForm(
            formKey: 'event_signup',
            formName: 'Event signup',
            contactAvailable: true,
            capabilityKey: 'tasks.create_task',
            input: ['task_template_key' => $template->key],
            context: $context,
        );
        $route = FlowRoute::query()->findOrFail($id);
        $point = $route->activeFlowRoutePoints()->sole();
        $this->assertSame('create_task', $point->type);
        $this->assertSame($template->key, $point->definition['task_template_key']);
        $this->assertTrue((bool) $route->is_active);
        $this->assertTrue(collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['automations'])
            ->firstWhere('id', $id)['quick_manageable']);
    }

    public function test_event_campaign_enrollment_uses_an_active_campaign(): void
    {
        $this->registerAfterSignupActionModules();
        $campaign = Campaign::factory()->create(['key' => 'event_follow_up_campaign']);
        $result = app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $this->assertSame([], $result['errors']);
        $workspace = app(FormSubmissionAutomationWorkspace::class);
        $context = new HostedFormContextReference('event', 42);
        $action = collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['actions'])
            ->firstWhere('key', 'campaigns.enroll_contact');

        $this->assertIsArray($action);
        $this->assertSame(['campaign_key'], array_column($action['inline_fields'], 'name'));

        $id = $workspace->createQuickActionForForm(
            formKey: 'event_signup',
            formName: 'Event signup',
            contactAvailable: true,
            capabilityKey: 'campaigns.enroll_contact',
            input: ['campaign_key' => $campaign->key],
            context: $context,
        );
        $route = FlowRoute::query()->findOrFail($id);
        $point = $route->activeFlowRoutePoints()->sole();
        $this->assertSame('enroll_campaign', $point->type);
        $this->assertSame($campaign->key, $point->definition['campaign_key']);
        $this->assertTrue((bool) $route->is_active);
        $this->assertTrue(collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['automations'])
            ->firstWhere('id', $id)['quick_manageable']);
    }

    public function test_event_message_uses_only_eligible_templates_and_forces_initiatory_mode(): void
    {
        $this->registerAfterSignupActionModules();
        $eligible = MessageTemplatePreset::factory()->create([
            'name' => 'Event welcome',
            'meta' => ['route_authoring' => ['eligible' => true]],
        ]);
        $ineligible = MessageTemplatePreset::factory()->create([
            'name' => 'Unapproved message',
            'meta' => [],
        ]);
        $result = app(SyncFlowRouteCapabilitiesAction::class)->handle();
        $this->assertSame([], $result['errors']);
        $workspace = app(FormSubmissionAutomationWorkspace::class);
        $context = new HostedFormContextReference('event', 42);
        $action = collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['actions'])
            ->firstWhere('key', 'messaging.send_message');

        $this->assertIsArray($action);
        $this->assertSame(
            ['message_role', 'message_template_preset_id'],
            array_column($action['inline_fields'], 'name'),
        );
        $this->assertSame('hidden', $action['inline_fields'][0]['type']);
        $offeredIds = array_column($action['inline_fields'][1]['options'], 'value');
        $this->assertContains($eligible->getKey(), $offeredIds);
        $this->assertNotContains($ineligible->getKey(), $offeredIds);

        $id = $workspace->createQuickActionForForm(
            formKey: 'event_signup',
            formName: 'Event signup',
            contactAvailable: true,
            capabilityKey: 'messaging.send_message',
            input: [
                // Browser-submitted hidden values must not switch to reply mode.
                'message_role' => 'reply',
                'message_template_preset_id' => $eligible->getKey(),
            ],
            context: $context,
        );
        $route = FlowRoute::query()->findOrFail($id);
        $point = $route->activeFlowRoutePoints()->sole();
        $this->assertSame('send_message', $point->type);
        $this->assertSame('initiatory', $point->definition['message_role']);
        $this->assertSame($eligible->key, $point->definition['message_template_preset_key'] ?? $point->definition['message_template_key'] ?? null);
        $this->assertTrue((bool) $route->is_active);
        $this->assertTrue(collect($workspace->readForForm('event_signup', 'Event signup', true, $context)['automations'])
            ->firstWhere('id', $id)['quick_manageable']);
    }

    private function registerAfterSignupActionModules(): void
    {
        config()->set('modules.enabled', ['forms', 'workflow', 'flow_routes', 'tasks', 'messaging', 'campaigns']);
        $this->app->register(TasksModuleServiceProvider::class);
        $this->app->register(MessagingModuleServiceProvider::class);
        $this->app->register(CampaignsModuleServiceProvider::class);

        foreach ([
            AutomationActionRegistry::class,
            AutomationCapabilityRegistry::class,
            AutomationPointDefinitionRegistry::class,
            AutomationPointAuthoringRegistry::class,
            QuickFormSubmissionAutomationService::class,
            FormSubmissionAutomationWorkspace::class,
        ] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }
}
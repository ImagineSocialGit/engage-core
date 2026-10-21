<?php

namespace Tests\Feature\Forms;

use App\Modules\FlowRoutes\Actions\SyncFlowRouteCapabilitiesAction;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\Forms\Data\HostedFormContextReference;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
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
            ['core.remove_contact_tag', ['tag' => 'example']],
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
}
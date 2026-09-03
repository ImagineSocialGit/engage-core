<?php

namespace Tests\Feature\Forms;

use App\Models\User;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\Forms\Automation\FormSubmissionAutomationTriggerAuthoringContributor;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use App\Support\ModuleIntegrations\Forms\Contracts\FormSubmissionAutomationWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormAfterSubmissionAutomationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'forms',
            'workflow',
            'flow_routes',
            'tasks',
            'messaging',
        ]);
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
                    'allowed_forms' => ['artist_updates'],
                    'domains' => ['example.com'],
                ],
            ],
        ]);

        $this->app->register(FormsModuleServiceProvider::class, true);
        $this->app->forgetInstance(AutomationTriggerAuthoringRegistry::class);
        $this->app->forgetInstance(FormSubmissionAutomationWorkspace::class);
    }

    public function test_forms_surface_exposes_guided_actions_from_registered_automation_capabilities(): void
    {
        $this->publishedForm('artist_updates');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('crm.forms.index'))
            ->assertOk();

        $afterSubmission = data_get(
            $response->viewData('overview'),
            'forms.0.after_submission',
        );

        $this->assertIsArray($afterSubmission);
        $this->assertTrue($afterSubmission['available']);
        $this->assertTrue($afterSubmission['contact_available']);
        $this->assertEqualsCanonicalizing(
            [
                'messaging.send_message',
                'tasks.create_task',
                'flow_routes.custom',
            ],
            collect($afterSubmission['actions'])->pluck('key')->all(),
        );

        $messageAction = collect($afterSubmission['actions'])
            ->firstWhere('key', 'messaging.send_message');

        $this->assertIsArray($messageAction);
        parse_str((string) parse_url($messageAction['url'], PHP_URL_QUERY), $query);
        $this->assertSame('1', (string) ($query['create'] ?? ''));
        $this->assertSame(FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR, $query['create_kind'] ?? null);
        $this->assertSame(FormSubmissionAutomationTriggerAuthoringContributor::KEY, $query['trigger_authoring_key'] ?? null);
        $this->assertSame('artist_updates', $query['form_key'] ?? null);
        $this->assertSame('messaging.send_message', $query['starter_capability_key'] ?? null);

        $response
            ->assertSee('data-form-after-submission="artist_updates"', false)
            ->assertSee('data-form-automation-action="messaging.send_message"', false)
            ->assertSee('data-form-automation-action="tasks.create_task"', false)
            ->assertSee('data-form-automation-action="flow_routes.custom"', false);
    }

    public function test_forms_surface_links_only_automations_scoped_to_that_form(): void
    {
        $this->publishedForm('artist_updates');
        $matching = $this->formRoute('artist_updates', 'Matching form follow-up');
        $this->formRoute('another_form', 'Different form follow-up');

        $overview = $this->actingAs(User::factory()->create())
            ->get(route('crm.forms.index'))
            ->assertOk()
            ->viewData('overview');

        $automations = data_get($overview, 'forms.0.after_submission.automations');

        $this->assertIsArray($automations);
        $this->assertCount(1, $automations);
        $this->assertSame($matching->getKey(), $automations[0]['id']);
        $this->assertSame(
            route('crm.flow-routes.index', ['edit_route' => $matching->getKey()]),
            $automations[0]['url'],
        );
    }

    public function test_flow_route_create_surface_accepts_generic_trigger_and_starter_prefill(): void
    {
        $this->publishedForm('artist_updates');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('crm.flow-routes.index', [
            'create' => 1,
            'create_kind' => FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
            'trigger_authoring_key' => FormSubmissionAutomationTriggerAuthoringContributor::KEY,
            'form_key' => 'artist_updates',
            'create_name' => 'Reply after Artist Updates',
            'starter_capability_key' => 'messaging.send_message',
        ]));

        $response->assertOk();
        $this->assertTrue($response->viewData('openCreateRoute'));
        $this->assertSame(
            FormSubmissionAutomationTriggerAuthoringContributor::KEY,
            $response->viewData('createRouteTriggerKey'),
        );
        $this->assertSame(
            'artist_updates',
            data_get($response->viewData('createRouteTriggerValues'), 'form_key'),
        );
        $this->assertSame('Reply after Artist Updates', $response->viewData('createRouteName'));
        $this->assertSame('messaging.send_message', $response->viewData('createRouteStarterCapabilityKey'));

        $store = $this->actingAs($user)->post(route('crm.flow-routes.store'), [
            'name' => 'Reply after Artist Updates',
            'authoring_kind' => FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
            'trigger_authoring_key' => FormSubmissionAutomationTriggerAuthoringContributor::KEY,
            'form_key' => 'artist_updates',
            'starter_capability_key' => 'messaging.send_message',
        ]);

        $route = FlowRoute::query()->sole();

        $store->assertRedirect(route('crm.flow-routes.index', [
            'edit_route' => $route->getKey(),
            'add_capability' => 'messaging.send_message',
        ]));
        $this->assertSame(FormSubmissionAutomationTriggerAuthoringContributor::EVENT_KEY, $route->trigger_key);
        $this->assertSame(
            'artist_updates',
            data_get($route->meta, 'definition.entry_conditions.0.value'),
        );
    }

    public function test_guided_actions_follow_current_module_availability(): void
    {
        config()->set('modules.enabled', [
            'forms',
            'workflow',
            'flow_routes',
            'messaging',
        ]);
        $this->app->forgetInstance(FormSubmissionAutomationWorkspace::class);
        $this->publishedForm('artist_updates');

        $actions = data_get(
            $this->actingAs(User::factory()->create())
                ->get(route('crm.forms.index'))
                ->assertOk()
                ->viewData('overview'),
            'forms.0.after_submission.actions',
        );

        $this->assertSame(
            [
                'messaging.send_message',
                'flow_routes.custom',
            ],
            collect($actions)->pluck('key')->values()->all(),
        );
    }

    public function test_forms_surface_stays_manual_only_when_flow_routes_is_unavailable(): void
    {
        config()->set('modules.enabled', ['forms', 'messaging']);
        $this->app->forgetInstance(FormSubmissionAutomationWorkspace::class);
        $this->publishedForm('artist_updates');

        $afterSubmission = data_get(
            $this->actingAs(User::factory()->create())
                ->get(route('crm.forms.index'))
                ->assertOk()
                ->viewData('overview'),
            'forms.0.after_submission',
        );

        $this->assertFalse($afterSubmission['available']);
        $this->assertSame([], $afterSubmission['actions']);
        $this->assertSame([], $afterSubmission['automations']);
    }

    private function publishedForm(string $key): FormDefinition
    {
        $definition = FormDefinition::factory()->active()->public()->create([
            'key' => $key,
            'name' => 'Artist Updates',
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'name' => 'Artist Updates',
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
                        'source' => 'engage_sites',
                        'subsource' => $key,
                    ],
                ],
            ],
        ]);

        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return $definition->refresh();
    }

    private function formRoute(string $formKey, string $name): FlowRoute
    {
        return FlowRoute::factory()->create([
            'name' => $name,
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => FormSubmissionAutomationTriggerAuthoringContributor::EVENT_KEY,
            'is_current_version' => true,
            'meta' => [
                'authoring' => [
                    'source' => 'crm',
                    'kind' => FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
                    'trigger_authoring_key' => FormSubmissionAutomationTriggerAuthoringContributor::KEY,
                ],
                'definition' => [
                    'entry_conditions' => [[
                        'source' => 'execution_meta',
                        'path' => FormSubmissionAutomationTriggerAuthoringContributor::FORM_KEY_EVENT_PATH,
                        'operator' => 'equals',
                        'value' => $formKey,
                    ]],
                ],
            ],
        ]);
    }
}
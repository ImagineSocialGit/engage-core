<?php

namespace Tests\Feature\Forms;

use App\Models\User;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\Forms\Automation\FormSubmissionAutomationTriggerAuthoringContributor;
use App\Modules\Forms\Data\HostedFormContextReference;
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
            'campaigns',
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

    public function test_flow_route_create_accepts_form_trigger_and_starter_prefill(): void
    {
        $this->publishedForm('artist_updates');
        $user = User::factory()->create();

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
        $this->assertSame(
            FormSubmissionAutomationTriggerAuthoringContributor::EVENT_KEY,
            $route->trigger_key,
        );
        $this->assertSame(
            'artist_updates',
            data_get($route->meta, 'definition.entry_conditions.0.value'),
        );
        $this->assertCount(
            1,
            data_get($route->meta, 'definition.entry_conditions', []),
        );
    }

    public function test_form_submission_trigger_can_be_scoped_to_one_hosted_context(): void
    {
        $this->publishedForm('artist_updates');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('crm.flow-routes.store'), [
            'name' => 'After Nashville signup',
            'authoring_kind' => FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
            'trigger_authoring_key' => FormSubmissionAutomationTriggerAuthoringContributor::KEY,
            'form_key' => 'artist_updates',
            'context_key' => 'event',
            'context_reference' => '42',
        ])->assertRedirect();

        $route = FlowRoute::query()->sole();
        $conditions = data_get($route->meta, 'definition.entry_conditions', []);

        $this->assertCount(3, $conditions);
        $this->assertSame(
            FormSubmissionAutomationTriggerAuthoringContributor::FORM_KEY_EVENT_PATH,
            $conditions[0]['path'],
        );
        $this->assertSame('artist_updates', $conditions[0]['value']);
        $this->assertSame(
            FormSubmissionAutomationTriggerAuthoringContributor::CONTEXT_KEY_EVENT_PATH,
            $conditions[1]['path'],
        );
        $this->assertSame('event', $conditions[1]['value']);
        $this->assertSame(
            FormSubmissionAutomationTriggerAuthoringContributor::CONTEXT_REFERENCE_EVENT_PATH,
            $conditions[2]['path'],
        );
        $this->assertSame('42', $conditions[2]['value']);
    }

    public function test_context_workspace_includes_global_and_matching_routes_but_not_other_contexts(): void
    {
        $this->publishedForm('artist_updates');

        $global = $this->formRoute(
            formKey: 'artist_updates',
            name: 'Global',
        );
        $nashville = $this->formRoute(
            formKey: 'artist_updates',
            name: 'Nashville',
            context: new HostedFormContextReference('event', 42),
        );
        $atlanta = $this->formRoute(
            formKey: 'artist_updates',
            name: 'Atlanta',
            context: new HostedFormContextReference('event', 43),
        );

        $workspace = app(FormSubmissionAutomationWorkspace::class);

        $result = $workspace->readForForm(
            formKey: 'artist_updates',
            formName: 'Artist Updates',
            contactAvailable: false,
            context: new HostedFormContextReference('event', 42),
            contextLabel: 'Nashville Show',
        );

        $ids = collect($result['automations'])
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $expected = [
            (int) $global->getKey(),
            (int) $nashville->getKey(),
        ];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertNotContains(
            (int) $atlanta->getKey(),
            $ids,
            true,
        );
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

    private function formRoute(
        string $formKey,
        string $name,
        ?HostedFormContextReference $context = null,
    ): FlowRoute {
        $conditions = [[
            'source' => 'execution_meta',
            'path' => FormSubmissionAutomationTriggerAuthoringContributor::FORM_KEY_EVENT_PATH,
            'operator' => 'equals',
            'value' => $formKey,
        ]];

        if ($context !== null) {
            $conditions[] = [
                'source' => 'execution_meta',
                'path' => FormSubmissionAutomationTriggerAuthoringContributor::CONTEXT_KEY_EVENT_PATH,
                'operator' => 'equals',
                'value' => $context->key,
            ];
            $conditions[] = [
                'source' => 'execution_meta',
                'path' => FormSubmissionAutomationTriggerAuthoringContributor::CONTEXT_REFERENCE_EVENT_PATH,
                'operator' => 'equals',
                'value' => $context->reference,
            ];
        }

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
                    'entry_conditions' => $conditions,
                ],
            ],
        ]);
    }
}
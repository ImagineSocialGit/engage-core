<?php

namespace Tests\Feature\InboundMessaging;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\InboundMessaging\Automation\InboundEmailRouteAutomationTriggerAuthoringContributor;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use App\Support\ModuleIntegrations\InboundMessaging\Contracts\InboundEmailRouteAutomationWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundEmailRouteAutomationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_address_workspace_hands_specific_address_to_flow_routes_and_lists_matching_automation(): void
    {
        $this->enableModules(withFlowRoutes: true);
        config()->set(
            'messaging.email.inbound_domain',
            'inbound.example.test',
        );
        $this->withoutMiddleware(ForceStagingAccess::class);

        $first = $this->route('website_forms', 'Website Forms');
        $second = $this->route('vendor_updates', 'Vendor Updates');

        $matching = FlowRoute::factory()
            ->forAutomationEvent(
                InboundEmailRouteAutomationTriggerAuthoringContributor::EVENT_KEY,
            )
            ->create([
                'name' => 'Website form follow up',
                'meta' => [
                    'authoring' => [
                        'kind' => FlowRoute::AUTHORING_KIND_ROUTE,
                    ],
                    'definition' => [
                        'entry_conditions' => [[
                            'source' => 'execution_meta',
                            'path' => InboundEmailRouteAutomationTriggerAuthoringContributor::ROUTE_KEY_EVENT_PATH,
                            'operator' => 'equals',
                            'value' => $first->key,
                        ]],
                    ],
                ],
            ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('crm.inbound-messaging.email-routes.index'))
            ->assertOk()
            ->assertSee('data-inbound-email-route-automation', false);

        $workspace = $response->viewData('workspace');
        $this->assertIsArray($workspace);
        $this->assertTrue($workspace['automation_available']);

        $firstRow = collect($workspace['routes'])->first(
            fn (array $row): bool => $row['route']->is($first),
        );
        $secondRow = collect($workspace['routes'])->first(
            fn (array $row): bool => $row['route']->is($second),
        );

        $this->assertIsArray($firstRow);
        $this->assertIsArray($secondRow);
        $this->assertContains(
            $matching->getKey(),
            collect($firstRow['automation']['automations'])->pluck('id')->all(),
        );
        $this->assertNotContains(
            $matching->getKey(),
            collect($secondRow['automation']['automations'])->pluck('id')->all(),
        );

        $this->assertIsString($firstRow['automation']['create_url']);
        parse_str(
            (string) parse_url(
                $firstRow['automation']['create_url'],
                PHP_URL_QUERY,
            ),
            $query,
        );

        $this->assertSame(
            InboundEmailRouteAutomationTriggerAuthoringContributor::KEY,
            $query['trigger_authoring_key'] ?? null,
        );
        $this->assertSame(
            $first->key,
            $query['inbound_email_route_key'] ?? null,
        );
    }

    public function test_inbox_remains_available_without_flow_routes_and_disabled_address_cannot_start_new_handoff(): void
    {
        $this->enableModules(withFlowRoutes: false);
        config()->set(
            'messaging.email.inbound_domain',
            'inbound.example.test',
        );
        $this->withoutMiddleware(ForceStagingAccess::class);

        $route = $this->route(
            key: 'manual_review',
            label: 'Manual Review',
            active: false,
        );

        $response = $this->actingAs(User::factory()->create())
            ->get(route('crm.inbound-messaging.email-routes.index'))
            ->assertOk();

        $workspace = $response->viewData('workspace');
        $row = collect($workspace['routes'])->first(
            fn (array $candidate): bool => $candidate['route']->is($route),
        );

        $this->assertFalse($workspace['automation_available']);
        $this->assertIsArray($row);
        $this->assertFalse($row['automation']['available']);
        $this->assertNull($row['automation']['create_url']);
        $this->assertEmpty($row['automation']['automations']);
    }

    private function route(
        string $key,
        string $label,
        bool $active = true,
    ): InboundEmailRoute {
        return InboundEmailRoute::query()->create([
            'key' => $key,
            'local_part' => str_replace('_', '-', $key),
            'label' => $label,
            'source' => 'crm',
            'context_key' => null,
            'is_active' => $active,
        ]);
    }

    private function enableModules(bool $withFlowRoutes): void
    {
        $modules = [
            'core',
            'messaging',
            'inbound_messaging',
        ];

        if ($withFlowRoutes) {
            $modules[] = 'workflow';
            $modules[] = 'flow_routes';
        }

        config()->set('modules.enabled', $modules);

        foreach ([
            AutomationTriggerAuthoringRegistry::class,
            InboundEmailRouteAutomationWorkspace::class,
        ] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }
}
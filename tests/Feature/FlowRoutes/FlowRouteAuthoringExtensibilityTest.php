<?php

namespace Tests\Feature\FlowRoutes;

use App\Models\User;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Services\FlowRouteAuthoringLinkBuilder;
use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use App\Support\AutomationTriggers\AutomationTriggerAuthoringRegistry;
use App\Support\AutomationTriggers\Contracts\AutomationTriggerAuthoringContributor;
use App\Support\AutomationTriggers\Data\AutomationTriggerAuthoringDefinition;
use App\Support\AutomationTriggers\Data\AutomationTriggerSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteAuthoringExtensibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_future_module_contributions_render_through_generic_authoring_seams(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
        ]);
        config()->set('modules.modules.future_module', [
            'name' => 'Future Module',
            'ui' => ['tone' => 'slate'],
            'depends_on' => [],
            'providers' => [],
        ]);

        $this->registerFutureAuthoringContributors();

        $capability = FlowRouteCapability::query()->create([
            'key' => 'future_module.perform',
            'module_key' => 'future_module',
            'capability_type' => FlowRouteCapability::TYPE_ACTION,
            'point_type' => FutureAutomationPointAuthoringContributor::POINT_TYPE,
            'handler_key' => FutureAutomationPointAuthoringContributor::POINT_TYPE,
            'event_key' => null,
            'action_key' => 'perform',
            'name' => 'Perform future action',
            'description' => 'Fixture capability for extension authoring.',
            'category' => 'future',
            'surface' => 'route_management',
            'supported_subjects' => [],
            'required_modules' => [],
            'input_schema' => [],
            'output_schema' => [],
            'available_fields' => [],
            'defaults' => [],
            'is_active' => true,
            'source' => 'test',
            'source_version' => null,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $route = FlowRoute::factory()->create([
            'name' => 'Extensibility fixture',
            'is_active' => false,
            'meta' => [
                'authoring' => [
                    'source' => 'crm',
                    'kind' => FlowRoute::AUTHORING_KIND_ROUTE,
                ],
            ],
        ]);

        $authoringLinks = app(FlowRouteAuthoringLinkBuilder::class);
        $createUrl = $authoringLinks->createUrl(
            triggerAuthoringKey: FutureAutomationTriggerAuthoringContributor::KEY,
            triggerValues: [
                'future_source' => 'website',
                'not_a_trigger_field' => 'discard-me',
            ],
            name: 'Future automation',
            kind: FlowRoute::AUTHORING_KIND_AUTOMATIC_BEHAVIOR,
            starterCapabilityKey: (string) $capability->key,
        );

        parse_str((string) parse_url($createUrl, PHP_URL_QUERY), $query);

        $this->assertSame(
            FutureAutomationTriggerAuthoringContributor::KEY,
            $query['trigger_authoring_key'] ?? null,
        );
        $this->assertSame('website', $query['future_source'] ?? null);
        $this->assertArrayNotHasKey('not_a_trigger_field', $query);
        $this->assertSame((string) $capability->key, $query['starter_capability_key'] ?? null);

        $user = User::factory()->create();

        $createResponse = $this->actingAs($user)
            ->get($createUrl)
            ->assertOk();

        $this->assertTrue($createResponse->viewData('openCreateRoute'));
        $this->assertSame(
            FutureAutomationTriggerAuthoringContributor::KEY,
            $createResponse->viewData('createRouteTriggerKey'),
        );
        $this->assertSame(
            'website',
            data_get($createResponse->viewData('createRouteTriggerValues'), 'future_source'),
        );

        $createResponse
            ->assertSee('data-flow-route-trigger-field="future_source"', false)
            ->assertSee('name="future_source"', false);

        $editorResponse = $this->actingAs($user)
            ->get($authoringLinks->editUrl($route))
            ->assertOk();

        $editor = $editorResponse->viewData('routeEditors')->get((int) $route->getKey());
        $futureCapability = collect($editor['capabilities'] ?? [])
            ->firstWhere('key', 'future_module.perform');

        $this->assertIsArray($futureCapability);
        $this->assertSame('Future action label', $futureCapability['name_field_label'] ?? null);
        $this->assertSame('future_value', data_get($futureCapability, 'fields.0.name'));

        $editorResponse
            ->assertSee('name="future_value"', false)
            ->assertSee('Future action label');
    }

    private function registerFutureAuthoringContributors(): void
    {
        $this->app->singleton(
            FutureAutomationTriggerAuthoringContributor::class,
            fn (): FutureAutomationTriggerAuthoringContributor => new FutureAutomationTriggerAuthoringContributor(),
        );
        $this->app->tag(
            [FutureAutomationTriggerAuthoringContributor::class],
            'automation.trigger_authoring_contributors',
        );

        $this->app->singleton(
            FutureAutomationPointAuthoringContributor::class,
            fn (): FutureAutomationPointAuthoringContributor => new FutureAutomationPointAuthoringContributor(),
        );
        $this->app->tag(
            [FutureAutomationPointAuthoringContributor::class],
            'automation.point_authoring_contributors',
        );

        $this->app->forgetInstance(AutomationTriggerAuthoringRegistry::class);
        $this->app->forgetInstance(AutomationPointAuthoringRegistry::class);
    }
}

final class FutureAutomationTriggerAuthoringContributor implements AutomationTriggerAuthoringContributor
{
    public const KEY = 'future_module.received';

    public function definitions(): iterable
    {
        yield new AutomationTriggerAuthoringDefinition(
            key: self::KEY,
            moduleKey: 'future_module',
            name: 'Future event occurs',
            description: 'Fixture trigger for extension authoring.',
        );
    }

    public function available(string $authoringKey): bool
    {
        return $authoringKey === self::KEY;
    }

    public function fields(string $authoringKey): array
    {
        return [[
            'type' => 'text',
            'name' => 'future_source',
            'label' => 'Future source',
            'required' => true,
            'placeholder' => 'Source key',
            'help' => 'Fixture text field proving trigger authoring is not select-only.',
        ]];
    }

    public function rules(string $authoringKey): array
    {
        return [
            'future_source' => ['required', 'string', 'max:80'],
        ];
    }

    public function selection(string $authoringKey, array $input): AutomationTriggerSelection
    {
        return new AutomationTriggerSelection(
            triggerType: FlowRoute::TRIGGER_AUTOMATION_EVENT,
            triggerKey: 'future.module.received',
            entryConditions: [[
                'source' => 'execution_meta',
                'path' => 'automation_event.meta.future_source',
                'operator' => 'equals',
                'value' => trim((string) ($input['future_source'] ?? '')),
            ]],
        );
    }
}

final class FutureAutomationPointAuthoringContributor implements AutomationPointAuthoringContributor
{
    public const POINT_TYPE = 'future_action';

    public function definitions(): iterable
    {
        yield new AutomationPointAuthoringDefinition(
            pointType: self::POINT_TYPE,
            moduleKey: 'future_module',
            name: 'Future action',
            description: 'Fixture action for extension authoring.',
            nameFieldLabel: 'Future action label',
        );
    }

    public function available(
        string $pointType,
        AutomationPointAuthoringContext $context,
    ): bool {
        return $pointType === self::POINT_TYPE;
    }

    public function fields(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): array {
        return [[
            'type' => 'text',
            'name' => 'future_value',
            'label' => 'Future value',
            'required' => true,
        ]];
    }

    public function rules(
        string $pointType,
        AutomationPointAuthoringContext $context,
    ): array {
        return [
            'future_value' => ['required', 'string', 'max:80'],
        ];
    }

    public function buildDefinition(
        string $pointType,
        array $input,
        AutomationPointAuthoringContext $context,
    ): array {
        return [
            'future_value' => trim((string) ($input['future_value'] ?? '')),
        ];
    }

    public function pointName(
        string $pointType,
        string $fallback,
        array $input,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        $name = trim((string) ($input['name'] ?? ''));

        return $name !== '' ? $name : $fallback;
    }

    public function summary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return 'Future action.';
    }

    public function editorSummary(
        string $pointType,
        array $definition,
        AutomationPointAuthoringContext $context,
    ): string {
        return 'Future action';
    }
}
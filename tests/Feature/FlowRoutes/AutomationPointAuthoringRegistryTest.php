<?php

namespace Tests\Feature\FlowRoutes;

use App\Support\AutomationCapabilities\AutomationPointAuthoringRegistry;
use App\Support\AutomationCapabilities\Contracts\AutomationPointAuthoringContributor;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringContext;
use App\Support\AutomationCapabilities\Data\AutomationPointAuthoringDefinition;
use InvalidArgumentException;
use Tests\TestCase;

class AutomationPointAuthoringRegistryTest extends TestCase
{
    public function test_enabled_modules_contribute_authoring_definitions(): void
    {
        config()->set('modules.enabled', [
            'workflow',
            'flow_routes',
            'tasks',
            'messaging',
            'campaigns',
        ]);

        $registry = app(AutomationPointAuthoringRegistry::class);

        $this->assertEqualsCanonicalizing([
            'wait',
            'change_status',
            'create_task',
            'send_message',
            'enroll_campaign',
            'cancel_campaign',
        ], $registry->registeredTypes());
    }


    public function test_registry_delegates_the_complete_authoring_lifecycle_to_a_contributor(): void
    {
        $contributor = new class implements AutomationPointAuthoringContributor {
            public function definitions(): iterable
            {
                yield new AutomationPointAuthoringDefinition('future_action', 'future_module', 'Future action', 'Future action description');
            }

            public function available(string $pointType, AutomationPointAuthoringContext $context): bool { return true; }
            public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array { return [['type' => 'text', 'name' => 'value']]; }
            public function rules(string $pointType, AutomationPointAuthoringContext $context): array { return ['value' => ['required', 'string']]; }
            public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array { return ['value' => (string) $input['value']]; }
            public function pointName(string $pointType, string $fallback, array $input, array $definition, AutomationPointAuthoringContext $context): string { return 'Future: '.$definition['value']; }
            public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string { return 'Future summary: '.$definition['value']; }
            public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string { return 'Future editor: '.$definition['value']; }
        };

        $registry = new AutomationPointAuthoringRegistry([$contributor]);
        $context = new AutomationPointAuthoringContext();
        $definition = $registry->buildDefinition('future_action', ['value' => 'works'], $context);

        $this->assertTrue($registry->available('future_action', $context));
        $this->assertSame([['type' => 'text', 'name' => 'value']], $registry->fields('future_action', [], $context));
        $this->assertSame(['value' => ['required', 'string']], $registry->rules('future_action', $context));
        $this->assertSame(['value' => 'works'], $definition);
        $this->assertSame('Future: works', $registry->pointName('future_action', 'Fallback', [], $definition, $context));
        $this->assertSame('Future summary: works', $registry->summary('future_action', $definition, $context));
        $this->assertSame('Future editor: works', $registry->editorSummary('future_action', $definition, $context));
    }

    public function test_registry_rejects_duplicate_point_types(): void
    {
        $contributor = new class implements AutomationPointAuthoringContributor {
            public function definitions(): iterable
            {
                yield new AutomationPointAuthoringDefinition('duplicate', 'test', 'First', 'First');
                yield new AutomationPointAuthoringDefinition('duplicate', 'test', 'Second', 'Second');
            }

            public function available(string $pointType, AutomationPointAuthoringContext $context): bool { return true; }
            public function fields(string $pointType, array $definition, AutomationPointAuthoringContext $context): array { return []; }
            public function rules(string $pointType, AutomationPointAuthoringContext $context): array { return []; }
            public function buildDefinition(string $pointType, array $input, AutomationPointAuthoringContext $context): array { return []; }
            public function pointName(string $pointType, string $fallback, array $input, array $definition, AutomationPointAuthoringContext $context): string { return $fallback; }
            public function summary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string { return 'Summary'; }
            public function editorSummary(string $pointType, array $definition, AutomationPointAuthoringContext $context): string { return 'Summary'; }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered for type [duplicate]');

        new AutomationPointAuthoringRegistry([$contributor]);
    }
}

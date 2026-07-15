<?php

namespace Tests\Feature\FlowRoutes;

use App\Support\AutomationCapabilities\AutomationActionRegistry;
use App\Support\AutomationCapabilities\AutomationPointDefinitionRegistry;
use App\Support\AutomationCapabilities\Contracts\AutomationActionHandler;
use App\Support\AutomationCapabilities\Contracts\AutomationPointDefinitionContributor;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use App\Support\AutomationCapabilities\Data\AutomationPointDefinition;
use App\Support\AutomationCapabilities\Data\AutomationPointValidationContext;
use App\Support\ConfigContracts\Data\ConfigSchema;
use InvalidArgumentException;
use Tests\TestCase;

class AutomationPointExtensionRegistryTest extends TestCase
{
    public function test_enabled_modules_contribute_point_definitions_without_flow_routes_owning_their_schemas(): void
    {
        $definitions = app(AutomationPointDefinitionRegistry::class)->definitions();

        $this->assertArrayHasKey('noop', $definitions);
        $this->assertArrayHasKey('wait', $definitions);
        $this->assertArrayHasKey('create_task', $definitions);
        $this->assertArrayHasKey('send_message', $definitions);
        $this->assertArrayHasKey('enroll_campaign', $definitions);
        $this->assertArrayHasKey('cancel_campaign', $definitions);
    }

    public function test_point_definition_registry_rejects_duplicate_point_types(): void
    {
        $contributor = new class implements AutomationPointDefinitionContributor {
            public function definitions(): iterable
            {
                yield new AutomationPointDefinition('duplicate', ConfigSchema::object([]));
                yield new AutomationPointDefinition('duplicate', ConfigSchema::object([]));
            }

            public function validate(
                string $pointType,
                array $definition,
                array $settings,
                AutomationPointValidationContext $context,
            ): iterable {
                return [];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate automation point definition type [duplicate].');

        (new AutomationPointDefinitionRegistry([$contributor]))->definitions();
    }

    public function test_action_registry_rejects_duplicate_action_keys(): void
    {
        $handler = new class implements AutomationActionHandler {
            public function key(): string
            {
                return 'example.action';
            }

            public function handle(AutomationActionContext $context): AutomationActionResult
            {
                return AutomationActionResult::completed('done');
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate automation action handler key [example.action].');

        (new AutomationActionRegistry([$handler, $handler]))->all();
    }

    public function test_cross_module_business_actions_register_through_neutral_action_registry(): void
    {
        $registry = app(AutomationActionRegistry::class);

        $this->assertTrue($registry->has('tasks.create_task'));
        $this->assertTrue($registry->has('messaging.dispatch_message'));
        $this->assertTrue($registry->has('campaigns.enroll_contact'));
        $this->assertTrue($registry->has('campaigns.cancel_enrollment'));
    }
}

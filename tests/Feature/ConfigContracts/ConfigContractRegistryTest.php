<?php

namespace Tests\Feature\ConfigContracts;

use App\Support\ConfigContracts\ConfigContractRegistry;
use App\Support\ConfigContracts\Data\ConfigContractViolation;
use Tests\TestCase;

class ConfigContractRegistryTest extends TestCase
{
    public function test_foundational_contracts_are_registered_by_their_owners(): void
    {
        $registry = app(ConfigContractRegistry::class);

        $this->assertSame([
            'app.module_definition',
            'app.preset_package',
            'campaigns.preset_definition',
            'core.contact_status_definition',
            'flow_routes.preset_definition',
            'messaging.email_definition',
            'messaging.permission_invitation',
            'messaging.sms_definition',
            'tasks.preset_definition',
            'webinars.message_area',
            'webinars.post_event',
            'webinars.schedule_profile',
        ], array_keys($registry->all()));

        $this->assertSame('app', $registry->get('app.module_definition')->owner());
        $this->assertSame('core', $registry->get('core.contact_status_definition')->owner());
        $this->assertSame('messaging', $registry->get('messaging.email_definition')->owner());
        $this->assertSame('tasks', $registry->get('tasks.preset_definition')->owner());
    }

    public function test_every_foundational_contract_example_is_executable(): void
    {
        foreach (app(ConfigContractRegistry::class)->all() as $contract) {
            $this->assertSame(
                [],
                $contract->schema()->validate($contract->example(), $contract->sourcePattern()),
                "Contract [{$contract->key()}] has an invalid canonical example.",
            );
        }
    }

    public function test_current_default_modules_and_packages_match_closed_contracts(): void
    {
        $registry = app(ConfigContractRegistry::class);
        $moduleContract = $registry->get('app.module_definition');
        $packageContract = $registry->get('app.preset_package');

        foreach (config('modules.modules', []) as $moduleKey => $definition) {
            $this->assertSame(
                [],
                $moduleContract->schema()->validate($definition, "modules.modules.{$moduleKey}"),
                "Module [{$moduleKey}] violates its canonical contract.",
            );
        }

        foreach (config('presets.packages', []) as $packageKey => $definition) {
            $this->assertSame(
                [],
                $packageContract->schema()->validate($definition, "presets.packages.{$packageKey}"),
                "Preset package [{$packageKey}] violates its canonical contract.",
            );
        }
    }

    public function test_current_contact_status_and_task_definitions_match_closed_contracts(): void
    {
        $registry = app(ConfigContractRegistry::class);
        $statusContract = $registry->get('core.contact_status_definition');
        $taskContract = $registry->get('tasks.preset_definition');

        foreach ([
            'presets.modules.core.contact-statuses',
            'presets.modules.webinars.contact-statuses',
        ] as $source) {
            foreach (config("{$source}.definitions", []) as $key => $definition) {
                $this->assertSame(
                    [],
                    $statusContract->schema()->validate($definition, "{$source}.definitions.{$key}"),
                    "ContactStatus definition [{$key}] violates its canonical contract.",
                );
            }
        }

        foreach ([
            'presets.modules.tasks.tasks',
            'presets.modules.webinars.tasks',
        ] as $source) {
            foreach (config("{$source}.definitions", []) as $key => $definition) {
                $this->assertSame(
                    [],
                    $taskContract->schema()->validate($definition, "{$source}.definitions.{$key}"),
                    "Task definition [{$key}] violates its canonical contract.",
                );
            }
        }
    }

    public function test_closed_contracts_reject_unknown_fields_and_invalid_nested_values(): void
    {
        $registry = app(ConfigContractRegistry::class);

        $moduleViolations = $registry->get('app.module_definition')->schema()->validate([
            'name' => 'Tasks',
            'depends_on' => ['core'],
            'providers' => [],
            'invented_behavior' => true,
        ], 'modules.modules.tasks');

        $packageViolations = $registry->get('app.preset_package')->schema()->validate([
            'modules' => [
                'enabled' => ['tasks'],
            ],
            'groups' => [
                'contact_statuses' => ['default'],
                'tasks' => ['default'],
                'campaigns' => [],
                'flow_routes' => [],
            ],
        ], 'presets.packages.basic');

        $taskViolations = $registry->get('tasks.preset_definition')->schema()->validate([
            'title' => 'Follow up',
            'responsible_party' => 'somebody_else',
            'defaults' => [
                'due' => [
                    'type' => 'someday',
                ],
            ],
        ], 'presets.modules.tasks.tasks.definitions.follow_up');

        $this->assertContains('unknown_field', $this->codes($moduleViolations));
        $this->assertContains('unknown_field', $this->codes($packageViolations));
        $this->assertSame([
            'value_not_allowed',
            'value_not_allowed',
        ], $this->codes($taskViolations));
    }

    public function test_explicitly_open_meta_accepts_module_owned_extension_data(): void
    {
        $violations = app(ConfigContractRegistry::class)
            ->get('core.contact_status_definition')
            ->schema()
            ->validate([
                'key' => 'engaged',
                'name' => 'Engaged',
                'meta' => [
                    'intent_level' => 'medium',
                    'automation_role' => 'nurture_or_follow_up',
                ],
            ], 'contact_status');

        $this->assertSame([], $violations);
    }

    public function test_contract_normalization_applies_declared_defaults_deterministically(): void
    {
        $normalized = app(ConfigContractRegistry::class)
            ->get('tasks.preset_definition')
            ->schema()
            ->normalize([
                'title' => 'Follow up',
            ]);

        $this->assertSame([
            'title' => 'Follow up',
            'responsible_party' => 'internal',
            'source' => 'preset',
            'is_active' => true,
            'link_defaults' => [],
            'defaults' => [],
            'meta' => [],
        ], $normalized);
    }

    /**
     * @param array<int, ConfigContractViolation> $violations
     * @return array<int, string>
     */
    private function codes(array $violations): array
    {
        return array_map(
            fn (ConfigContractViolation $violation): string => $violation->code,
            $violations,
        );
    }
}
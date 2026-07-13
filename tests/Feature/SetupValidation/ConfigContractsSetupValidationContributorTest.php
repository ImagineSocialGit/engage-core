<?php

namespace Tests\Feature\SetupValidation;

use App\Support\ConfigContracts\ConfigContractRegistry;
use App\Support\ConfigContracts\ConfigContractTargetRegistry;
use App\Support\ConfigContracts\Contracts\ConfigContract;
use App\Support\ConfigContracts\Contracts\ConfigContractTargetProvider;
use App\Support\ConfigContracts\Data\ConfigContractTarget;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;
use App\Support\ConfigContracts\Data\ConfigField;
use App\Support\ConfigContracts\Data\ConfigSchema;
use App\Support\SetupValidation\Contributors\ConfigContractsSetupValidationContributor;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ConfigContractsSetupValidationContributorTest extends TestCase
{
    public function test_it_maps_closed_contract_violations_to_standard_setup_findings_with_full_identity(): void
    {
        $contract = new class implements ConfigContract
        {
            public function key(): string
            {
                return 'test.definition';
            }

            public function owner(): string
            {
                return 'test_module';
            }

            public function sourcePattern(): string
            {
                return 'test.definitions.{definition_key}';
            }

            public function schema(): ConfigSchema
            {
                return ConfigSchema::object([
                    'name' => ConfigField::required(ConfigSchema::string()),
                ]);
            }

            public function example(): array
            {
                return ['name' => 'Example'];
            }
        };

        $provider = new class implements ConfigContractTargetProvider
        {
            public function contractKeys(): array
            {
                return ['test.definition'];
            }

            public function targets(ConfigContractTargetContext $context): iterable
            {
                yield new ConfigContractTarget(
                    contractKey: 'test.definition',
                    path: 'test.definitions.example',
                    value: [
                        'name' => 'Example',
                        'invented_field' => true,
                    ],
                    context: [
                        'definition_key' => 'example',
                    ],
                );
            }
        };

        $contracts = new ConfigContractRegistry([$contract]);
        $targets = new ConfigContractTargetRegistry(
            contracts: $contracts,
            providers: [$provider],
        );

        $result = (new SetupValidationManager([
            new ConfigContractsSetupValidationContributor($contracts, $targets),
        ]))->validate();

        $finding = $result->findings()[0];

        $this->assertSame('error', $finding->severity);
        $this->assertSame('config_contract.unknown_field', $finding->code);
        $this->assertSame('test.definitions.example', $finding->source);
        $this->assertSame('test.definitions.example.invented_field', $finding->path);
        $this->assertSame('test_module', $finding->module);
        $this->assertSame('test.definition', $finding->context['contract_key']);
        $this->assertSame('test.definitions.example', $finding->context['target_path']);
        $this->assertSame('example', $finding->context['definition_key']);
        $this->assertSame('test.definitions.{definition_key}', $finding->meta['contract_source_pattern']);
        $this->assertSame('unknown_field', $finding->meta['violation_code']);
    }

    public function test_app_level_setup_validation_enforces_registered_contracts_against_current_config(): void
    {
        Config::set('modules.modules.tasks.invented_behavior', true);

        $finding = collect(app(SetupValidationManager::class)->validate()->findings())
            ->first(fn ($finding): bool => $finding->code === 'config_contract.unknown_field'
                && $finding->path === 'modules.modules.tasks.invented_behavior');

        $this->assertNotNull($finding);
        $this->assertSame('app.module_definition', $finding->context['contract_key']);
        $this->assertSame('modules.modules.tasks', $finding->source);
        $this->assertSame('app', $finding->module);
    }
}

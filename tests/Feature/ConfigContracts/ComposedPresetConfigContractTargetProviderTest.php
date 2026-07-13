<?php

namespace Tests\Feature\ConfigContracts;

use App\Support\ConfigContracts\Data\ConfigContractTargetContext;
use App\Support\ConfigContracts\TargetProviders\ComposedPresetConfigContractTargetProvider;
use App\Support\Modules\ModuleManager;
use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\Data\PresetContribution;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetContributionRegistry;
use App\Support\Presets\PresetPackageResolver;
use Illuminate\Support\Facades\Config;
use LogicException;
use Tests\TestCase;

class ComposedPresetConfigContractTargetProviderTest extends TestCase
{
    public function test_it_preserves_raw_selected_definition_arrays_and_real_contributor_provenance(): void
    {
        Config::set('presets.packages.contract_target_test', [
            'groups' => [
                'tasks' => ['selected'],
            ],
        ]);

        $registry = new PresetContributionRegistry([
            $this->contributor([
                new PresetContribution(
                    contributor: 'tasks',
                    domain: PresetDomain::Tasks,
                    groups: [
                        'selected' => ['follow_up'],
                    ],
                    definitions: [
                        'follow_up' => [
                            'title' => 'Follow up',
                            'invented_field' => 'must_survive_composition',
                        ],
                    ],
                    source: 'presets.modules.tasks.tasks',
                ),
            ]),
        ]);

        $packageResolver = new PresetPackageResolver(
            moduleManager: app(ModuleManager::class),
        );

        $provider = new class (
            new PresetCompositionResolver(
                registry: $registry,
                packageResolver: $packageResolver,
            ),
            $packageResolver,
        ) extends ComposedPresetConfigContractTargetProvider
        {
            protected function contractKey(): string
            {
                return 'tasks.preset_definition';
            }

            protected function presetDomain(): PresetDomain
            {
                return PresetDomain::Tasks;
            }
        };

        $targets = iterator_to_array($provider->targets(
            ConfigContractTargetContext::current('contract_target_test'),
        ), false);

        $this->assertCount(1, $targets);
        $this->assertSame(
            'presets.modules.tasks.tasks.definitions.follow_up',
            $targets[0]->path,
        );
        $this->assertSame(
            'must_survive_composition',
            $targets[0]->value['invented_field'],
        );
        $this->assertSame('tasks', $targets[0]->context['contributor']);
        $this->assertSame(['selected'], $targets[0]->context['group_keys']);
    }

    public function test_it_refuses_to_silently_validate_proposed_state_through_the_current_composition_resolver(): void
    {
        $packageResolver = new PresetPackageResolver(
            moduleManager: app(ModuleManager::class),
        );

        $provider = new class (
            new PresetCompositionResolver(
                registry: new PresetContributionRegistry([]),
                packageResolver: $packageResolver,
            ),
            $packageResolver,
        ) extends ComposedPresetConfigContractTargetProvider
        {
            protected function contractKey(): string
            {
                return 'tasks.preset_definition';
            }

            protected function presetDomain(): PresetDomain
            {
                return PresetDomain::Tasks;
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Composed preset config contract target discovery does not yet support proposed state.'
        );

        iterator_to_array($provider->targets(
            ConfigContractTargetContext::proposed([], presetKey: 'future_export'),
        ));
    }

    /**
     * @param array<int, PresetContribution> $contributions
     */
    private function contributor(array $contributions): PresetContributor
    {
        return new class ($contributions) implements PresetContributor
        {
            /**
             * @param array<int, PresetContribution> $contributions
             */
            public function __construct(
                private readonly array $contributions,
            ) {}

            public function contributions(): iterable
            {
                yield from $this->contributions;
            }
        };
    }
}

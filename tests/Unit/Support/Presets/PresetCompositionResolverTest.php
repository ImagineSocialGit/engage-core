<?php

namespace Tests\Unit\Support\Presets;

use App\Support\Modules\ModuleManager;
use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\Data\PresetContribution;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetContributionRegistry;
use App\Support\Presets\PresetPackageResolver;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class PresetCompositionResolverTest extends TestCase
{
    public function test_it_resolves_selected_groups_and_deduplicates_shared_definition_references(): void
    {
        Config::set('presets.packages.basic', [
            'groups' => [
                'tasks' => ['general_default', 'extended_default'],
            ],
        ]);

        $registry = new PresetContributionRegistry([
            $this->contributor([
                new PresetContribution(
                    contributor: 'tasks',
                    domain: PresetDomain::Tasks,
                    groups: [
                        'general_default' => ['general.follow_up'],
                        'extended_default' => ['general.follow_up', 'general.review'],
                    ],
                    definitions: [
                        'general.follow_up' => ['title' => 'Follow up'],
                        'general.review' => ['title' => 'Review'],
                    ],
                    source: 'presets.modules.tasks.tasks',
                ),
            ]),
        ]);

        $resolved = $this->resolver($registry)->resolve(
            presetKey: 'basic',
            domain: PresetDomain::Tasks,
        );

        $this->assertSame(
            ['general.follow_up', 'general.review'],
            $resolved->definitionKeys,
        );

        $this->assertSame(
            ['general_default', 'extended_default'],
            $resolved->definitionGroups['general.follow_up'],
        );
    }

    public function test_duplicate_group_keys_across_contributors_are_rejected(): void
    {
        $registry = new PresetContributionRegistry([
            $this->contributor([
                new PresetContribution(
                    contributor: 'tasks',
                    domain: PresetDomain::Tasks,
                    groups: ['general_default' => ['general.follow_up']],
                    definitions: ['general.follow_up' => ['title' => 'Follow up']],
                    source: 'tasks',
                ),
            ]),
            $this->contributor([
                new PresetContribution(
                    contributor: 'webinars',
                    domain: PresetDomain::Tasks,
                    groups: ['general_default' => ['webinar.review_reply']],
                    definitions: ['webinar.review_reply' => ['title' => 'Review reply']],
                    source: 'webinars',
                ),
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('group [general_default] is defined by multiple contributors');

        $registry->groups(PresetDomain::Tasks);
    }

    public function test_duplicate_definition_keys_across_contributors_are_rejected(): void
    {
        $registry = new PresetContributionRegistry([
            $this->contributor([
                new PresetContribution(
                    contributor: 'tasks',
                    domain: PresetDomain::Tasks,
                    groups: ['general_default' => ['general.follow_up']],
                    definitions: ['general.follow_up' => ['title' => 'Follow up']],
                    source: 'tasks',
                ),
            ]),
            $this->contributor([
                new PresetContribution(
                    contributor: 'webinars',
                    domain: PresetDomain::Tasks,
                    groups: ['webinar_default' => ['general.follow_up']],
                    definitions: ['general.follow_up' => ['title' => 'Webinar follow up']],
                    source: 'webinars',
                ),
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('definition [general.follow_up] is defined by multiple contributors');

        $registry->definitions(PresetDomain::Tasks);
    }

    public function test_selected_missing_group_is_rejected(): void
    {
        Config::set('presets.packages.basic', [
            'groups' => [
                'tasks' => ['missing_group'],
            ],
        ]);

        $registry = new PresetContributionRegistry([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('selects missing [tasks] preset group [missing_group]');

        $this->resolver($registry)->resolve(
            presetKey: 'basic',
            domain: PresetDomain::Tasks,
        );
    }

    /**
     * @param array<int, PresetContribution> $contributions
     */
    private function contributor(array $contributions): PresetContributor
    {
        return new class($contributions) implements PresetContributor
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

    private function resolver(PresetContributionRegistry $registry): PresetCompositionResolver
    {
        return new PresetCompositionResolver(
            registry: $registry,
            packageResolver: new PresetPackageResolver(
                moduleManager: app(ModuleManager::class),
            ),
        );
    }
}

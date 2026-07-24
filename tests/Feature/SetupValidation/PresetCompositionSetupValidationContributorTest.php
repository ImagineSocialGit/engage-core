<?php

namespace Tests\Feature\SetupValidation;

use App\Support\Modules\ModuleManager;
use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\Data\PresetContribution;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetContributionRegistry;
use App\Support\Presets\PresetPackageResolver;
use App\Support\SetupValidation\Contributors\PresetCompositionSetupValidationContributor;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PresetCompositionSetupValidationContributorTest extends TestCase
{
    public function test_it_reports_missing_selected_package(): void
    {
        Config::set('presets.default_package', 'missing');
        Config::set('client.preset', null);
        Config::set('presets.packages', []);

        $findings = $this->findings([]);

        $this->assertContains(
            'app.presets.selected_package_missing',
            array_column($findings, 'code'),
        );
    }

    public function test_it_reports_missing_selected_group(): void
    {
        $this->package([
            'groups' => [
                'tasks' => ['missing_group'],
            ],
        ]);

        $findings = $this->findings([]);

        $this->assertContains(
            'app.presets.selected_group_missing',
            array_column($findings, 'code'),
        );
    }

    public function test_it_reports_group_reference_to_missing_definition(): void
    {
        $this->package([
            'groups' => [
                'tasks' => ['default'],
            ],
        ]);

        $findings = $this->findings([
            $this->contribution(
                contributor: 'tasks',
                domain: PresetDomain::Tasks,
                groups: [
                    'default' => ['general.missing'],
                ],
                definitions: [],
            ),
        ]);

        $this->assertContains(
            'app.presets.group_definition_missing',
            array_column($findings, 'code'),
        );
    }

    public function test_it_reports_duplicate_group_keys_across_contributors(): void
    {
        $this->package();

        $findings = $this->findings([
            $this->contribution(
                contributor: 'tasks',
                domain: PresetDomain::Tasks,
                groups: ['shared_default' => ['general.follow_up']],
                definitions: ['general.follow_up' => ['title' => 'Follow up']],
            ),
            $this->contribution(
                contributor: 'webinars',
                domain: PresetDomain::Tasks,
                groups: ['shared_default' => ['webinar.review_reply']],
                definitions: ['webinar.review_reply' => ['title' => 'Review reply']],
            ),
        ]);

        $this->assertContains(
            'app.presets.duplicate_group_key',
            array_column($findings, 'code'),
        );
    }

    public function test_it_reports_duplicate_definition_keys_across_contributors(): void
    {
        $this->package();

        $findings = $this->findings([
            $this->contribution(
                contributor: 'tasks',
                domain: PresetDomain::Tasks,
                groups: ['default' => ['general.follow_up']],
                definitions: ['general.follow_up' => ['title' => 'Follow up']],
            ),
            $this->contribution(
                contributor: 'webinars',
                domain: PresetDomain::Tasks,
                groups: ['webinar_default' => ['general.follow_up']],
                definitions: ['general.follow_up' => ['title' => 'Webinar follow up']],
            ),
        ]);

        $this->assertContains(
            'app.presets.duplicate_definition_key',
            array_column($findings, 'code'),
        );
    }

    public function test_it_warns_when_selected_group_comes_from_disabled_module(): void
    {
        Config::set('modules.modules.webinars', [
            'depends_on' => ['core'],
            'providers' => [],
        ]);

        Config::set('modules.enabled', ['tasks']);

        $this->package([
            'groups' => [
                'tasks' => ['webinar_default'],
            ],
        ]);

        $findings = $this->findings([
            $this->contribution(
                contributor: 'webinars',
                domain: PresetDomain::Tasks,
                groups: ['webinar_default' => ['webinar.review_reply']],
                definitions: ['webinar.review_reply' => ['title' => 'Review reply']],
            ),
        ]);

        $warning = collect($findings)->firstWhere(
            'code',
            'app.presets.selected_contributor_disabled',
        );

        $this->assertNotNull($warning);
        $this->assertSame('warning', $warning['severity']);
        $this->assertSame('webinars', $warning['context']['contributor']);
    }

    /**
     * @param array<string, mixed> $package
     */
    private function package(array $package = []): void
    {
        Config::set('client.preset', null);
        Config::set('presets.default_package', 'test');
        Config::set('presets.packages.test', array_replace_recursive([
            'groups' => [
                'contact_statuses' => [],
                'tasks' => [],
                'campaigns' => [],
                'flow_routes' => [],
            ],
        ], $package));
    }

    /**
     * @param array<int, PresetContribution> $contributions
     * @return array<int, array<string, mixed>>
     */
    private function findings(array $contributions): array
    {
        $registry = new PresetContributionRegistry([
            new class($contributions) implements PresetContributor
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
            },
        ]);

        $moduleManager = app(ModuleManager::class);

        $contributor = new PresetCompositionSetupValidationContributor(
            presetContributionRegistry: $registry,
            presetPackageResolver: new PresetPackageResolver(),
            moduleManager: $moduleManager,
        );

        return array_map(
            fn ($finding): array => $finding->toArray(),
            iterator_to_array($contributor->findings(), false),
        );
    }

    /**
     * @param array<string, array<int, string>> $groups
     * @param array<string, array<string, mixed>> $definitions
     */
    private function contribution(
        string $contributor,
        PresetDomain $domain,
        array $groups,
        array $definitions,
    ): PresetContribution {
        return new PresetContribution(
            contributor: $contributor,
            domain: $domain,
            groups: $groups,
            definitions: $definitions,
            source: "presets.modules.{$contributor}.{$domain->value}",
        );
    }
}
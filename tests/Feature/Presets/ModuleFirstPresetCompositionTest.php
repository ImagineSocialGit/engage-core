<?php

namespace Tests\Feature\Presets;

use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use App\Support\Presets\PresetContributionRegistry;
use Tests\TestCase;

class ModuleFirstPresetCompositionTest extends TestCase
{
    public function test_core_exposes_only_the_three_generic_packages(): void
    {
        $this->assertSame(
            ['basic', 'messaging', 'automated_messaging'],
            array_keys(config('presets.packages', [])),
        );
    }

    public function test_registry_exposes_module_first_contributions(): void
    {
        $registry = app(PresetContributionRegistry::class);

        $this->assertArrayHasKey(
            'general_default',
            $registry->groups(PresetDomain::ContactStatuses),
        );

        $this->assertArrayHasKey(
            'webinar_default',
            $registry->groups(PresetDomain::FlowRoutes),
        );

        $this->assertArrayHasKey(
            'webinar_attended_nurture',
            $registry->definitions(PresetDomain::Campaigns),
        );
    }

    public function test_webinar_package_resolves_cross_contributor_status_references(): void
    {
        config()->set('presets.packages.mortgage', [
            'groups' => [
                'contact_statuses' => [
                    'webinar_default',
                ],
            ],
        ]);

        $resolved = app(PresetCompositionResolver::class)->resolve(
            presetKey: 'mortgage',
            domain: PresetDomain::ContactStatuses,
        );

        $this->assertContains('new', $resolved->definitionKeys);
        $this->assertContains('registered', $resolved->definitionKeys);
        $this->assertContains('attended_webinar', $resolved->definitionKeys);
        $this->assertContains('missed_webinar', $resolved->definitionKeys);

        $this->assertSame(
            'core',
            $resolved->provenance['new']['contributor'],
        );

        $this->assertSame(
            'webinars',
            $resolved->provenance['registered']['contributor'],
        );
    }
}

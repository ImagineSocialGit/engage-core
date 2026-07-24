<?php

namespace Tests\Feature\ConfigContracts;

use App\Support\ConfigContracts\ConfigContractRegistry;
use Tests\TestCase;

class DomainConfigContractTest extends TestCase
{
    public function test_current_campaign_and_flow_route_presets_match_closed_contracts(): void
    {
        $registry = app(ConfigContractRegistry::class);

        foreach (['presets.modules.webinars.campaigns'] as $source) {
            foreach (config("{$source}.definitions", []) as $key => $definition) {
                $this->assertSame([], $registry->get('campaigns.preset_definition')->schema()->validate($definition, "{$source}.definitions.{$key}"));
            }
        }

        foreach ([
            'presets.modules.webinars.flow-routes',
            'presets.modules.client.flow-routes',
        ] as $source) {
            foreach (config("{$source}.definitions", []) as $key => $definition) {
                $this->assertSame([], $registry->get('flow_routes.preset_definition')->schema()->validate($definition, "{$source}.definitions.{$key}"));
            }
        }
    }

    public function test_campaign_contract_rejects_legacy_is_active_lifecycle_field(): void
    {
        $definition = app(ConfigContractRegistry::class)
            ->get('campaigns.preset_definition')
            ->example();

        $definition['is_active'] = false;

        $violations = app(ConfigContractRegistry::class)
            ->get('campaigns.preset_definition')
            ->schema()
            ->validate($definition, 'campaign');

        $this->assertContains(
            'unknown_field',
            array_map(fn ($violation) => $violation->code, $violations),
        );
    }

    public function test_campaign_contract_rejects_verbose_derived_identity_and_order_fields(): void
    {
        $contract = app(ConfigContractRegistry::class)
            ->get('campaigns.preset_definition');
        $definition = $contract->example();

        $definition['key'] = 'follow_up';
        $definition['channel'] = 'email';
        $definition['dispatch_key'] = 'campaign_step_due';
        $definition['steps'][0]['step_number'] = 1;
        $definition['steps'][0]['dispatch_key'] = 'campaign_step_due';
        $definition['steps'][0]['variants']['email']['key'] = 'email';
        $definition['steps'][0]['variants']['email']['sort_order'] = 10;
        $definition['steps'][0]['variants']['email']['purpose'] = 'marketing';
        $definition['steps'][0]['variants']['email']['scope'] = 'nurture';

        $violations = $contract->schema()->validate($definition, 'campaign');
        $unknownPaths = array_values(array_map(
            fn ($violation): string => $violation->path,
            array_filter(
                $violations,
                fn ($violation): bool => $violation->code === 'unknown_field',
            ),
        ));

        foreach ([
            'campaign.key',
            'campaign.channel',
            'campaign.dispatch_key',
            'campaign.steps.0.step_number',
            'campaign.steps.0.dispatch_key',
            'campaign.steps.0.variants.email.key',
            'campaign.steps.0.variants.email.sort_order',
            'campaign.steps.0.variants.email.purpose',
            'campaign.steps.0.variants.email.scope',
        ] as $path) {
            $this->assertContains($path, $unknownPaths);
        }
    }

    public function test_current_webinar_orchestration_matches_closed_contracts(): void
    {
        $registry = app(ConfigContractRegistry::class);
        $this->assertSame([], $registry->get('webinars.post_event')->schema()->validate(config('webinars.post_event'), 'webinars.post_event'));

        foreach (config('webinars.schedule_profiles', []) as $key => $profile) {
            $this->assertSame([], $registry->get('webinars.schedule_profile')->schema()->validate($profile, "webinars.schedule_profiles.{$key}"));
        }
    }

    public function test_flow_route_contract_rejects_removed_route_and_point_authoring_fields(): void
    {
        $contract = app(ConfigContractRegistry::class)
            ->get('flow_routes.preset_definition');
        $definition = $contract->example();

        $definition['key'] = 'legacy_route';
        $definition['trigger'] = ['type' => 'manual'];
        $definition['meta'] = [];
        $definition['status'] = 'active';
        $definition['points']['start']['capability_key'] = 'flow_routes.noop';
        $definition['points']['start']['conditions'] = [];

        $violations = $contract->schema()->validate($definition, 'flow_route');
        $unknownPaths = array_values(array_map(
            fn ($violation): string => $violation->path,
            array_filter(
                $violations,
                fn ($violation): bool => $violation->code === 'unknown_field',
            ),
        ));

        foreach ([
            'flow_route.key',
            'flow_route.trigger',
            'flow_route.meta',
            'flow_route.status',
        ] as $path) {
            $this->assertContains($path, $unknownPaths);
        }

        $this->assertContains(
            'flow_route.points.start',
            array_map(fn ($violation): string => $violation->path, $violations),
        );
    }
}
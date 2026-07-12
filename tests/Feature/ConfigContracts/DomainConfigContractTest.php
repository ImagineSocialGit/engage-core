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

        foreach (['presets.modules.webinars.flow-routes'] as $source) {
            foreach (config("{$source}.definitions", []) as $key => $definition) {
                $this->assertSame([], $registry->get('flow_routes.preset_definition')->schema()->validate($definition, "{$source}.definitions.{$key}"));
            }
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

    public function test_flow_route_contract_rejects_template_fields_ignored_by_runtime_dto(): void
    {
        $definition = app(ConfigContractRegistry::class)->get('flow_routes.preset_definition')->example();
        $definition['status'] = 'active';
        $definition['points'][0]['conditions'] = [];

        $violations = app(ConfigContractRegistry::class)->get('flow_routes.preset_definition')->schema()->validate($definition, 'flow_route');

        $this->assertContains('unknown_field', array_map(fn ($violation) => $violation->code, $violations));
    }
}

<?php

namespace App\Modules\FlowRoutes\ConfigContracts;

use App\Support\ConfigContracts\TargetProviders\ComposedPresetConfigContractTargetProvider;
use App\Support\Presets\Enums\PresetDomain;

final class FlowRoutePresetConfigContractTargetProvider extends ComposedPresetConfigContractTargetProvider
{
    protected function contractKey(): string
    {
        return 'flow_routes.preset_definition';
    }

    protected function presetDomain(): PresetDomain
    {
        return PresetDomain::FlowRoutes;
    }
}

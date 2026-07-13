<?php

namespace App\Modules\Campaigns\ConfigContracts;

use App\Support\ConfigContracts\TargetProviders\ComposedPresetConfigContractTargetProvider;
use App\Support\Presets\Enums\PresetDomain;

final class CampaignPresetConfigContractTargetProvider extends ComposedPresetConfigContractTargetProvider
{
    protected function contractKey(): string
    {
        return 'campaigns.preset_definition';
    }

    protected function presetDomain(): PresetDomain
    {
        return PresetDomain::Campaigns;
    }
}

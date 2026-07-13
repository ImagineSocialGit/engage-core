<?php

namespace App\Modules\Tasks\ConfigContracts;

use App\Support\ConfigContracts\TargetProviders\ComposedPresetConfigContractTargetProvider;
use App\Support\Presets\Enums\PresetDomain;

final class TaskPresetConfigContractTargetProvider extends ComposedPresetConfigContractTargetProvider
{
    protected function contractKey(): string
    {
        return 'tasks.preset_definition';
    }

    protected function presetDomain(): PresetDomain
    {
        return PresetDomain::Tasks;
    }
}

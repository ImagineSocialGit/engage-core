<?php

namespace App\Modules\Forms\ConfigContracts;

use App\Support\ConfigContracts\TargetProviders\ComposedPresetConfigContractTargetProvider;
use App\Support\Presets\Enums\PresetDomain;

final class FormDefinitionConfigContractTargetProvider extends ComposedPresetConfigContractTargetProvider
{
    protected function contractKey(): string
    {
        return 'forms.form_definition';
    }

    protected function presetDomain(): PresetDomain
    {
        return PresetDomain::Forms;
    }
}
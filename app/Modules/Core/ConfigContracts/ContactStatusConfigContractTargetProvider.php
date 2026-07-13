<?php

namespace App\Modules\Core\ConfigContracts;

use App\Support\ConfigContracts\TargetProviders\ComposedPresetConfigContractTargetProvider;
use App\Support\Presets\Enums\PresetDomain;

final class ContactStatusConfigContractTargetProvider extends ComposedPresetConfigContractTargetProvider
{
    protected function contractKey(): string
    {
        return 'core.contact_status_definition';
    }

    protected function presetDomain(): PresetDomain
    {
        return PresetDomain::ContactStatuses;
    }
}

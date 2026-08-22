<?php

namespace App\Modules\Forms\Presets;

use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\Data\PresetContribution;
use App\Support\Presets\Enums\PresetDomain;

final class FormsPresetContributor implements PresetContributor
{
    public function contributions(): iterable
    {
        $config = config('presets.modules.forms.forms', []);

        if (is_array($config) && $config !== []) {
            yield new PresetContribution(
                contributor: 'forms',
                domain: PresetDomain::Forms,
                groups: is_array($config['groups'] ?? null) ? $config['groups'] : [],
                definitions: is_array($config['definitions'] ?? null) ? $config['definitions'] : [],
                source: 'presets.modules.forms.forms',
            );
        }
    }
}
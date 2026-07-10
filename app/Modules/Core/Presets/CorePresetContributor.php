<?php

namespace App\Modules\Core\Presets;

use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\Data\PresetContribution;
use App\Support\Presets\Enums\PresetDomain;

final class CorePresetContributor implements PresetContributor
{
    public function contributions(): iterable
    {
        $config = config('presets.modules.core.contact-statuses', []);

        if (is_array($config) && $config !== []) {
            yield new PresetContribution(
                contributor: 'core',
                domain: PresetDomain::ContactStatuses,
                groups: is_array($config['groups'] ?? null) ? $config['groups'] : [],
                definitions: is_array($config['definitions'] ?? null) ? $config['definitions'] : [],
                source: 'presets.modules.core.contact-statuses',
            );
        }
    }
}

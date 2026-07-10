<?php

namespace App\Modules\Tasks\Presets;

use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\Data\PresetContribution;
use App\Support\Presets\Enums\PresetDomain;

final class TasksPresetContributor implements PresetContributor
{
    public function contributions(): iterable
    {
        $config = config('presets.modules.tasks.tasks', []);

        if (is_array($config) && $config !== []) {
            yield new PresetContribution(
                contributor: 'tasks',
                domain: PresetDomain::Tasks,
                groups: is_array($config['groups'] ?? null) ? $config['groups'] : [],
                definitions: is_array($config['definitions'] ?? null) ? $config['definitions'] : [],
                source: 'presets.modules.tasks.tasks',
            );
        }
    }
}

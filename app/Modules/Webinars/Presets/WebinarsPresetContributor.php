<?php

namespace App\Modules\Webinars\Presets;

use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\Data\PresetContribution;
use App\Support\Presets\Enums\PresetDomain;

final class WebinarsPresetContributor implements PresetContributor
{
    public function contributions(): iterable
    {
        $config = config('presets.modules.webinars.contact-statuses', []);

        if (is_array($config) && $config !== []) {
            yield new PresetContribution(
                contributor: 'webinars',
                domain: PresetDomain::ContactStatuses,
                groups: is_array($config['groups'] ?? null) ? $config['groups'] : [],
                definitions: is_array($config['definitions'] ?? null) ? $config['definitions'] : [],
                source: 'presets.modules.webinars.contact-statuses',
            );
        }
        $config = config('presets.modules.webinars.tasks', []);

        if (is_array($config) && $config !== []) {
            yield new PresetContribution(
                contributor: 'webinars',
                domain: PresetDomain::Tasks,
                groups: is_array($config['groups'] ?? null) ? $config['groups'] : [],
                definitions: is_array($config['definitions'] ?? null) ? $config['definitions'] : [],
                source: 'presets.modules.webinars.tasks',
            );
        }
        $config = config('presets.modules.webinars.campaigns', []);

        if (is_array($config) && $config !== []) {
            yield new PresetContribution(
                contributor: 'webinars',
                domain: PresetDomain::Campaigns,
                groups: is_array($config['groups'] ?? null) ? $config['groups'] : [],
                definitions: is_array($config['definitions'] ?? null) ? $config['definitions'] : [],
                source: 'presets.modules.webinars.campaigns',
            );
        }
        $config = config('presets.modules.webinars.flow-routes', []);

        if (is_array($config) && $config !== []) {
            yield new PresetContribution(
                contributor: 'webinars',
                domain: PresetDomain::FlowRoutes,
                groups: is_array($config['groups'] ?? null) ? $config['groups'] : [],
                definitions: is_array($config['definitions'] ?? null) ? $config['definitions'] : [],
                source: 'presets.modules.webinars.flow-routes',
            );
        }
    }
}

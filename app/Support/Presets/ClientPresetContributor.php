<?php

namespace App\Support\Presets;

use App\Support\Presets\Contracts\PresetContributor;
use App\Support\Presets\Data\PresetContribution;
use App\Support\Presets\Enums\PresetDomain;

final class ClientPresetContributor implements PresetContributor
{
    public function contributions(): iterable
    {
        $clientKey = config('client.key');

        if (! is_string($clientKey) || trim($clientKey) === '') {
            return;
        }

        $clientKey = trim($clientKey);

        foreach ($this->domainConfigKeys() as $domainValue => $configKey) {
            $domain = PresetDomain::from($domainValue);

            $config = config($configKey, []);

            if (! is_array($config) || $config === []) {
                continue;
            }

            yield new PresetContribution(
                contributor: (string) config('client.key', 'client'),
                domain: $domain,
                groups: is_array($config['groups'] ?? null) ? $config['groups'] : [],
                definitions: is_array($config['definitions'] ?? null) ? $config['definitions'] : [],
                source: $configKey,
            );
        }
    }

    /**
     * @return array<PresetDomain, string>
     */
    private function domainConfigKeys(): array
    {
        return [
            PresetDomain::ContactStatuses->value => 'presets.modules.client.contact-statuses',
            PresetDomain::Tasks->value => 'presets.modules.client.tasks',
            PresetDomain::Campaigns->value => 'presets.modules.client.campaigns',
            PresetDomain::FlowRoutes->value => 'presets.modules.client.flow-routes',
        ];
    }
}

<?php

namespace App\Support\ConfigContracts\TargetProviders;

use App\Support\ConfigContracts\Contracts\ConfigContractTargetProvider;
use App\Support\ConfigContracts\Data\ConfigContractTarget;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;

final class AppConfigContractTargetProvider implements ConfigContractTargetProvider
{
    public function contractKeys(): array
    {
        return [
            'app.module_definition',
            'app.preset_package',
        ];
    }

    public function targets(ConfigContractTargetContext $context): iterable
    {
        $modules = $context->config('modules.modules', []);

        if (is_array($modules)) {
            foreach ($modules as $moduleKey => $definition) {
                yield new ConfigContractTarget(
                    contractKey: 'app.module_definition',
                    path: 'modules.modules.'.(string) $moduleKey,
                    value: $definition,
                    context: [
                        'module_key' => $moduleKey,
                    ],
                );
            }
        }

        $packages = $context->config('presets.packages', []);

        if (is_array($packages)) {
            foreach ($packages as $packageKey => $definition) {
                yield new ConfigContractTarget(
                    contractKey: 'app.preset_package',
                    path: 'presets.packages.'.(string) $packageKey,
                    value: $definition,
                    context: [
                        'preset_key' => $packageKey,
                    ],
                );
            }
        }
    }
}

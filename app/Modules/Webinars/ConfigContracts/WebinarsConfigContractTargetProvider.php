<?php

namespace App\Modules\Webinars\ConfigContracts;

use App\Support\ConfigContracts\Contracts\ConfigContractTargetProvider;
use App\Support\ConfigContracts\Data\ConfigContractTarget;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;

final class WebinarsConfigContractTargetProvider implements ConfigContractTargetProvider
{
    public function contractKeys(): array
    {
        return [
            'webinars.message_area',
            'webinars.post_event',
            'webinars.schedule_profile',
        ];
    }

    public function targets(ConfigContractTargetContext $context): iterable
    {
        $messageAreas = $context->config('webinars.message_areas', []);

        if (is_array($messageAreas)) {
            foreach ($messageAreas as $areaKey => $area) {
                yield new ConfigContractTarget(
                    contractKey: 'webinars.message_area',
                    path: 'webinars.message_areas.'.(string) $areaKey,
                    value: $area,
                    context: [
                        'area_key' => $areaKey,
                    ],
                );
            }
        }

        $profiles = $context->config('webinars.schedule_profiles', []);

        if (is_array($profiles)) {
            foreach ($profiles as $profileKey => $profile) {
                yield new ConfigContractTarget(
                    contractKey: 'webinars.schedule_profile',
                    path: 'webinars.schedule_profiles.'.(string) $profileKey,
                    value: $profile,
                    context: [
                        'profile_key' => $profileKey,
                    ],
                );
            }
        }

        yield new ConfigContractTarget(
            contractKey: 'webinars.post_event',
            path: 'webinars.post_event',
            value: $context->config('webinars.post_event', []),
        );
    }
}
